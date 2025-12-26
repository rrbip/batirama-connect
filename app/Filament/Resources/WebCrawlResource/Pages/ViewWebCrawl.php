<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebCrawlResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\WebCrawlResource;
use App\Jobs\Crawler\CrawlUrlJob;
use App\Jobs\Crawler\StartWebCrawlJob;
use App\Models\WebCrawlUrl;
use App\Models\WebCrawlUrlCrawl;
use App\Services\Crawler\UrlNormalizer;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class ViewWebCrawl extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = WebCrawlResource::class;

    protected static string $view = 'filament.resources.web-crawl-resource.pages.view-web-crawl';

    public function getTitle(): string
    {
        return 'Détails du Crawl';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reset')
                ->label('Tout supprimer')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Supprimer toutes les données et recommencer')
                ->modalDescription('Cette action va supprimer toutes les URLs crawlées, les documents créés et les fichiers en cache. Le crawl redémarrera de zéro.')
                ->modalSubmitActionLabel('Oui, tout supprimer')
                ->action(function () {
                    // Supprimer les fichiers en cache
                    foreach ($this->record->urlEntries()->with('url')->get() as $entry) {
                        if ($entry->url?->storage_path && Storage::disk('local')->exists($entry->url->storage_path)) {
                            Storage::disk('local')->delete($entry->url->storage_path);
                        }
                    }

                    // Supprimer les documents créés par ce crawl (un par un pour déclencher l'Observer)
                    // L'Observer supprime automatiquement les vecteurs de Qdrant et les chunks
                    foreach ($this->record->documents()->with('chunks')->get() as $document) {
                        $document->chunks()->delete(); // Supprimer les chunks d'abord
                        $document->forceDelete(); // Force delete pour déclencher forceDeleting et bypasser SoftDelete
                    }

                    // Supprimer toutes les entrées d'URLs
                    $this->record->urlEntries()->delete();

                    // Réinitialiser les compteurs
                    $this->record->update([
                        'status' => 'pending',
                        'pages_discovered' => 0,
                        'pages_crawled' => 0,
                        'pages_indexed' => 0,
                        'pages_skipped' => 0,
                        'pages_error' => 0,
                        'documents_found' => 0,
                        'images_found' => 0,
                        'total_size_bytes' => 0,
                        'started_at' => null,
                        'completed_at' => null,
                        'paused_at' => null,
                    ]);

                    Notification::make()
                        ->title('Données supprimées')
                        ->body('Toutes les données ont été supprimées. Vous pouvez maintenant relancer le crawl.')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('start_crawl')
                ->label('Lancer le crawl')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => $this->record->status === 'pending' && $this->record->urlEntries()->count() === 0)
                ->action(function () {
                    StartWebCrawlJob::dispatch($this->record);

                    Notification::make()
                        ->title('Crawl lancé')
                        ->body('Le crawl a démarré.')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('recrawl')
                ->label('Relancer le crawl')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn () => $this->record->isCompleted())
                ->requiresConfirmation()
                ->modalHeading('Relancer le crawl du site')
                ->modalDescription('Cette action va re-crawler tout le site, mettre à jour les pages existantes et découvrir les nouvelles pages.')
                ->action(function () {
                    // Réinitialiser les compteurs pour un nouveau crawl
                    $this->record->update([
                        'status' => 'pending',
                        'pages_crawled' => 0,
                        'pages_discovered' => $this->record->urlCrawls()->count(),
                        'pages_indexed' => 0,
                        'pages_skipped' => 0,
                        'pages_error' => 0,
                        'started_at' => null,
                        'completed_at' => null,
                        'error_message' => null,
                    ]);

                    // Remettre toutes les URLs en attente
                    $this->record->urlCrawls()->update([
                        'status' => 'pending',
                        'error_message' => null,
                        'retry_count' => 0,
                    ]);

                    // Lancer le job de crawl
                    StartWebCrawlJob::dispatch($this->record);

                    Notification::make()
                        ->title('Crawl relancé')
                        ->body('Le site va être re-crawlé. Les nouvelles pages seront ajoutées.')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('pause')
                ->label('Pause')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn () => $this->record->status === 'running')
                ->action(function () {
                    $this->record->update([
                        'status' => 'paused',
                        'paused_at' => now(),
                    ]);
                    Notification::make()->title('Crawl mis en pause')->success()->send();
                }),

            Actions\Action::make('resume')
                ->label('Reprendre')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => $this->record->status === 'paused')
                ->action(function () {
                    $this->record->update([
                        'status' => 'running',
                        'paused_at' => null,
                    ]);
                    // Redémarrer le crawl
                    StartWebCrawlJob::dispatch($this->record);
                    Notification::make()->title('Crawl repris')->success()->send();
                }),

            Actions\Action::make('cancel')
                ->label('Annuler')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, ['running', 'paused', 'pending']))
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update([
                        'status' => 'cancelled',
                        'completed_at' => now(),
                    ]);
                    Notification::make()->title('Crawl annulé')->warning()->send();
                }),

            Actions\Action::make('edit')
                ->label('Modifier')
                ->icon('heroicon-o-pencil')
                ->url(fn () => $this->getResource()::getUrl('edit', ['record' => $this->record])),

            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->isCompleted()),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Informations générales')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('start_url')
                                    ->label('URL de départ')
                                    ->url(fn ($record) => $record->start_url)
                                    ->openUrlInNewTab(),

                                TextEntry::make('agent.name')
                                    ->label('Agent IA'),

                                TextEntry::make('status')
                                    ->label('Statut')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'pending' => 'gray',
                                        'running' => 'warning',
                                        'paused' => 'info',
                                        'completed' => 'success',
                                        'failed', 'cancelled' => 'danger',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn ($state) => match ($state) {
                                        'pending' => 'En attente',
                                        'running' => 'En cours',
                                        'paused' => 'Pausé',
                                        'completed' => 'Terminé',
                                        'failed' => 'Échoué',
                                        'cancelled' => 'Annulé',
                                        default => $state,
                                    }),
                            ]),
                    ]),

                Section::make('Statistiques')
                    ->schema([
                        Grid::make(5)
                            ->schema([
                                TextEntry::make('pages_discovered')
                                    ->label('Découvertes')
                                    ->numeric(),

                                TextEntry::make('pages_crawled')
                                    ->label('Crawlées')
                                    ->numeric(),

                                TextEntry::make('pages_indexed')
                                    ->label('Indexées')
                                    ->numeric()
                                    ->color('success'),

                                TextEntry::make('pages_skipped')
                                    ->label('Ignorées')
                                    ->numeric()
                                    ->color('warning'),

                                TextEntry::make('pages_error')
                                    ->label('Erreurs')
                                    ->numeric()
                                    ->color('danger'),
                            ]),

                        Grid::make(4)
                            ->schema([
                                TextEntry::make('images_found')
                                    ->label('Images')
                                    ->numeric(),

                                TextEntry::make('documents_found')
                                    ->label('Documents')
                                    ->numeric(),

                                TextEntry::make('total_size_for_humans')
                                    ->label('Taille totale'),

                                TextEntry::make('progress_percent')
                                    ->label('Progression')
                                    ->suffix('%'),
                            ]),
                    ]),

                Section::make('Configuration')
                    ->collapsed()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('max_depth')
                                    ->label('Profondeur max'),

                                TextEntry::make('max_pages')
                                    ->label('Limite pages'),

                                TextEntry::make('delay_ms')
                                    ->label('Délai (ms)'),

                                TextEntry::make('user_agent')
                                    ->label('User-Agent'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('url_filter_mode')
                                    ->label('Mode filtrage')
                                    ->formatStateUsing(fn ($state) => $state === 'include' ? 'Inclusion' : 'Exclusion'),

                                TextEntry::make('url_patterns')
                                    ->label('Patterns')
                                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : '-'),
                            ]),
                    ]),

                Section::make('Dates')
                    ->collapsed()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label('Créé le')
                                    ->dateTime('d/m/Y H:i'),

                                TextEntry::make('started_at')
                                    ->label('Démarré le')
                                    ->dateTime('d/m/Y H:i'),

                                TextEntry::make('completed_at')
                                    ->label('Terminé le')
                                    ->dateTime('d/m/Y H:i'),

                                TextEntry::make('last_crawled_at')
                                    ->label('Dernier crawl')
                                    ->dateTime('d/m/Y H:i'),
                            ]),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WebCrawlUrlCrawl::query()
                    ->where('crawl_id', $this->record->id)
                    ->with(['url', 'document'])
            )
            ->columns([
                Tables\Columns\TextColumn::make('url.url')
                    ->label('URL')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->url?->url)
                    ->searchable(query: function ($query, string $search) {
                        return $query->whereHas('url', function ($q) use ($search) {
                            $q->where('url', 'like', "%{$search}%");
                        });
                    }),

                Tables\Columns\TextColumn::make('depth')
                    ->label('Prof.')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'secondary' => 'pending',
                        'warning' => fn ($state) => in_array($state, ['fetching', 'fetched']),
                        'success' => 'indexed',
                        'info' => 'skipped',
                        'danger' => 'error',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'En attente',
                        'fetching' => 'Téléchargement',
                        'fetched' => 'Téléchargé',
                        'indexed' => 'Indexé',
                        'skipped' => 'Ignoré',
                        'error' => 'Erreur',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('url.http_status')
                    ->label('HTTP')
                    ->color(fn ($state) => match (true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 300 && $state < 400 => 'warning',
                        $state >= 400 => 'danger',
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('skip_reason_label')
                    ->label('Raison')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('url.content_type')
                    ->label('Type')
                    ->limit(20),

                Tables\Columns\TextColumn::make('document.title')
                    ->label('Document')
                    ->limit(30)
                    ->url(fn ($record) => $record->document_id
                        ? DocumentResource::getUrl('edit', ['record' => $record->document_id])
                        : null),

                Tables\Columns\TextColumn::make('fetched_at')
                    ->label('Crawlé le')
                    ->dateTime('d/m H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente',
                        'fetching' => 'Téléchargement',
                        'fetched' => 'Téléchargé',
                        'indexed' => 'Indexé',
                        'skipped' => 'Ignoré',
                        'error' => 'Erreur',
                    ]),

                Tables\Filters\SelectFilter::make('http_status')
                    ->label('HTTP')
                    ->options([
                        '2xx' => '2xx (Succès)',
                        '3xx' => '3xx (Redirection)',
                        '4xx' => '4xx (Erreur client)',
                        '5xx' => '5xx (Erreur serveur)',
                    ])
                    ->query(function ($query, array $data) {
                        if (empty($data['value'])) {
                            return $query;
                        }
                        $range = match ($data['value']) {
                            '2xx' => [200, 299],
                            '3xx' => [300, 399],
                            '4xx' => [400, 499],
                            '5xx' => [500, 599],
                            default => null,
                        };
                        if ($range) {
                            return $query->whereHas('url', fn ($q) => $q->whereBetween('http_status', $range));
                        }
                        return $query;
                    }),

                Tables\Filters\SelectFilter::make('content_type')
                    ->label('Type')
                    ->options([
                        'html' => 'HTML',
                        'pdf' => 'PDF',
                        'image' => 'Image',
                        'other' => 'Autre',
                    ])
                    ->query(function ($query, array $data) {
                        if (empty($data['value'])) {
                            return $query;
                        }
                        return $query->whereHas('url', function ($q) use ($data) {
                            match ($data['value']) {
                                'html' => $q->where('content_type', 'like', '%text/html%'),
                                'pdf' => $q->where('content_type', 'like', '%pdf%'),
                                'image' => $q->where('content_type', 'like', 'image/%'),
                                'other' => $q->where('content_type', 'not like', '%text/html%')
                                    ->where('content_type', 'not like', '%pdf%')
                                    ->where('content_type', 'not like', 'image/%'),
                            };
                        });
                    }),

                Tables\Filters\SelectFilter::make('depth')
                    ->label('Profondeur')
                    ->options(fn () => collect(range(0, 10))->mapWithKeys(fn ($d) => [$d => "Niveau $d"])->toArray()),
            ])
            ->actions([
                Tables\Actions\Action::make('view_cache')
                    ->label('Voir')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn ($record) => !empty($record->url?->storage_path))
                    ->modalHeading(fn ($record) => 'Aperçu: ' . \Illuminate\Support\Str::limit($record->url?->url, 50))
                    ->modalContent(function ($record) {
                        $storagePath = $record->url->storage_path;

                        // Vérifier si le fichier existe
                        if (!Storage::disk('local')->exists($storagePath)) {
                            return view('filament.components.cached-content-missing', [
                                'url' => $record->url->url,
                                'storagePath' => $storagePath,
                            ]);
                        }

                        $content = Storage::disk('local')->get($storagePath);
                        $contentType = $record->url->content_type ?? 'text/plain';
                        $url = $record->url->url;
                        $isHtml = str_contains($contentType, 'text/html');

                        // Pour le HTML, remplacer les URLs par les versions en cache
                        $cachedResources = [];
                        if ($isHtml) {
                            $cachedResources = $this->getCachedResources($content, $url);
                        }

                        return view('filament.components.cached-content-viewer', [
                            'content' => $content,
                            'contentType' => $contentType,
                            'url' => $url,
                            'isHtml' => $isHtml,
                            'isImage' => str_starts_with($contentType, 'image/'),
                            'isPdf' => str_contains($contentType, 'pdf'),
                            'cachedResources' => $cachedResources,
                        ]);
                    })
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer'),

                Tables\Actions\Action::make('refetch')
                    ->label('Mettre à jour')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->visible(fn ($record) => in_array($record->status, ['indexed', 'fetched', 'error', 'skipped']))
                    ->requiresConfirmation()
                    ->modalHeading('Mettre à jour cette page')
                    ->modalDescription(fn ($record) => "Re-télécharger et ré-indexer: {$record->url?->url}")
                    ->action(function ($record) {
                        // Si le crawl est terminé, le passer en 'running' pour permettre le re-fetch
                        if ($this->record->isCompleted()) {
                            $this->record->update(['status' => 'running']);
                        }

                        // Remettre en attente
                        $record->update([
                            'status' => 'pending',
                            'error_message' => null,
                            'retry_count' => 0,
                        ]);

                        // Dispatcher le job pour cette URL spécifique
                        CrawlUrlJob::dispatch($this->record, $record);

                        Notification::make()
                            ->title('Mise à jour lancée')
                            ->body('La page sera re-téléchargée et ré-indexée.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('view_url')
                    ->label('Ouvrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn ($record) => empty($record->url?->storage_path)) // Seulement si pas de cache
                    ->url(fn ($record) => $record->url?->url)
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('refetch_selected')
                    ->label('Mettre à jour la sélection')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        // Si le crawl est terminé, le passer en 'running' pour permettre le re-fetch
                        if ($this->record->isCompleted()) {
                            $this->record->update(['status' => 'running']);
                        }

                        $count = 0;
                        foreach ($records as $record) {
                            $record->update([
                                'status' => 'pending',
                                'error_message' => null,
                                'retry_count' => 0,
                            ]);
                            CrawlUrlJob::dispatch($this->record, $record);
                            $count++;
                        }

                        Notification::make()
                            ->title('Mise à jour lancée')
                            ->body("{$count} page(s) seront re-téléchargées et ré-indexées.")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('5s');
    }

    /**
     * Récupère les ressources en cache (CSS, images) pour une page HTML
     */
    protected function getCachedResources(string $html, string $baseUrl): array
    {
        $resources = [];
        $urlNormalizer = app(UrlNormalizer::class);

        // Extraire l'URL de base
        $parsedBase = parse_url($baseUrl);
        $baseScheme = $parsedBase['scheme'] ?? 'https';
        $baseHost = $parsedBase['host'] ?? '';
        $basePath = dirname($parsedBase['path'] ?? '/');

        // Patterns pour trouver les URLs de ressources
        $patterns = [
            // Images: src="..." ou src='...'
            '/<img[^>]+src=["\']([^"\']+)["\']/i',
            // CSS: href="..." sur link rel="stylesheet"
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]*>/i',
            // Background images dans le style
            '/url\(["\']?([^"\')\s]+)["\']?\)/i',
        ];

        $foundUrls = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $resourceUrl) {
                    // Ignorer les data URIs et URLs absolues externes
                    if (str_starts_with($resourceUrl, 'data:')) {
                        continue;
                    }

                    // Résoudre l'URL relative
                    if (!str_starts_with($resourceUrl, 'http')) {
                        if (str_starts_with($resourceUrl, '//')) {
                            $resourceUrl = $baseScheme . ':' . $resourceUrl;
                        } elseif (str_starts_with($resourceUrl, '/')) {
                            $resourceUrl = $baseScheme . '://' . $baseHost . $resourceUrl;
                        } else {
                            $resourceUrl = $baseScheme . '://' . $baseHost . $basePath . '/' . $resourceUrl;
                        }
                    }

                    $foundUrls[] = $resourceUrl;
                }
            }
        }

        // Chercher les ressources en cache
        foreach (array_unique($foundUrls) as $resourceUrl) {
            $urlHash = $urlNormalizer->hash($resourceUrl);

            $cachedUrl = WebCrawlUrl::where('url_hash', $urlHash)->first();

            if ($cachedUrl && $cachedUrl->storage_path && Storage::disk('local')->exists($cachedUrl->storage_path)) {
                $content = Storage::disk('local')->get($cachedUrl->storage_path);
                $mimeType = $cachedUrl->content_type ?? 'application/octet-stream';

                // Convertir en data URI
                $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($content);

                $resources[$resourceUrl] = $dataUri;
            }
        }

        return $resources;
    }
}
