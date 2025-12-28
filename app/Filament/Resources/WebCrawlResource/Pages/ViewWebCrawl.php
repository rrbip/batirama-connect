<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebCrawlResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\WebCrawlResource;
use App\Jobs\Crawler\CrawlUrlJob;
use App\Jobs\Crawler\IndexAgentUrlJob;
use App\Jobs\Crawler\StartWebCrawlJob;
use App\Models\Agent;
use App\Models\AgentWebCrawl;
use App\Models\WebCrawlUrl;
use App\Models\WebCrawlUrlCrawl;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Marketplace\LanguageDetector;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
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

    public ?int $editingAgentConfigId = null;

    public array $editFormData = [
        'url_filter_mode' => 'exclude',
        'url_patterns' => '',
        'content_types' => ['html', 'pdf', 'image', 'document'],
        'allowed_locales' => [],
        'chunk_strategy' => '',
    ];

    public function getTitle(): string
    {
        return 'Détails du Crawl';
    }

    /**
     * Ouvre le modal d'édition pour un agent config.
     */
    public function editAgentConfig(int $configId): void
    {
        $config = AgentWebCrawl::find($configId);

        if (! $config || $config->web_crawl_id !== $this->record->id) {
            return;
        }

        $this->editingAgentConfigId = $configId;
        $this->editFormData = [
            'url_filter_mode' => $config->url_filter_mode ?? 'exclude',
            'url_patterns' => is_array($config->url_patterns) ? implode("\n", $config->url_patterns) : '',
            'content_types' => $config->content_types ?? ['html', 'pdf', 'image', 'document'],
            'allowed_locales' => $config->allowed_locales ?? [],
            'chunk_strategy' => $config->chunk_strategy ?? '',
        ];

        $this->dispatch('open-modal', id: 'edit-agent-config');
    }

    /**
     * Supprime un agent config et ses documents.
     */
    public function deleteAgentConfig(int $configId): void
    {
        $config = AgentWebCrawl::find($configId);

        if (! $config || $config->web_crawl_id !== $this->record->id) {
            Notification::make()
                ->title('Erreur')
                ->body('Configuration non trouvée.')
                ->danger()
                ->send();

            return;
        }

        // Collecter les IDs des URLs du cache pour ce crawl
        $crawlUrlIds = $this->record->urlEntries()
            ->pluck('crawl_url_id')
            ->filter()
            ->toArray();

        // Supprimer les documents de cet agent pour ce crawl
        // 1. Via web_crawl_id (relation directe)
        // 2. Via crawl_url_id (fallback pour anciens documents sans web_crawl_id)
        // Important: forceDelete déclenche l'observer qui supprime de Qdrant
        $documents = \App\Models\Document::where('agent_id', $config->agent_id)
            ->where(function ($query) use ($crawlUrlIds) {
                $query->where('web_crawl_id', $this->record->id)
                    ->orWhereIn('crawl_url_id', $crawlUrlIds);
            })
            ->get();

        foreach ($documents as $document) {
            // L'observer DocumentObserver::forceDeleting supprime de Qdrant
            // AVANT de supprimer les chunks
            $document->forceDelete();
        }

        // Supprimer les entrées d'indexation
        $config->urlEntries()->delete();

        // Supprimer la configuration
        $config->delete();

        Notification::make()
            ->title('Agent supprimé')
            ->body('L\'agent a été retiré du crawl et ses documents supprimés.')
            ->success()
            ->send();
    }

    /**
     * Sauvegarde les modifications d'un agent config.
     */
    public function saveAgentConfig(): void
    {
        $config = AgentWebCrawl::find($this->editingAgentConfigId);

        if (! $config || $config->web_crawl_id !== $this->record->id) {
            Notification::make()
                ->title('Erreur')
                ->body('Configuration non trouvée.')
                ->danger()
                ->send();

            return;
        }

        // Convertir les patterns
        $patterns = $this->editFormData['url_patterns']
            ? array_filter(array_map('trim', explode("\n", $this->editFormData['url_patterns'])))
            : [];

        $config->update([
            'url_filter_mode' => $this->editFormData['url_filter_mode'],
            'url_patterns' => $patterns,
            'content_types' => $this->editFormData['content_types'],
            'allowed_locales' => $this->editFormData['allowed_locales'] ?? [],
            'chunk_strategy' => $this->editFormData['chunk_strategy'] ?: null,
        ]);

        $this->editingAgentConfigId = null;
        $this->dispatch('close-modal', id: 'edit-agent-config');

        Notification::make()
            ->title('Configuration sauvegardée')
            ->success()
            ->send();
    }

    /**
     * Retourne les données du formulaire d'édition.
     */
    public function getEditAgentConfigFormData(): array
    {
        if (! $this->editingAgentConfigId) {
            return [];
        }

        $config = AgentWebCrawl::find($this->editingAgentConfigId);

        if (! $config) {
            return [];
        }

        return [
            'url_filter_mode' => $config->url_filter_mode,
            'url_patterns' => is_array($config->url_patterns) ? implode("\n", $config->url_patterns) : '',
            'content_types' => $config->content_types ?? ['html', 'pdf', 'image', 'document'],
            'allowed_locales' => $config->allowed_locales ?? [],
            'chunk_strategy' => $config->chunk_strategy ?? '',
        ];
    }

    /**
     * Lance l'indexation pour un agent spécifique (appelé depuis la vue).
     */
    public function startAgentIndexation(int $configId): void
    {
        $config = AgentWebCrawl::find($configId);

        if (! $config || $config->web_crawl_id !== $this->record->id) {
            Notification::make()
                ->title('Erreur')
                ->body('Configuration non trouvée.')
                ->danger()
                ->send();

            return;
        }

        // Compter d'abord pour afficher l'estimation
        $estimatedCount = $this->countUrlsToIndex($config);

        if ($estimatedCount === 0) {
            Notification::make()
                ->title('Aucune URL à indexer')
                ->body('Aucune URL ne correspond aux filtres configurés (patterns, types, langues).')
                ->warning()
                ->send();

            return;
        }

        $config->update(['index_status' => 'indexing']);

        // Dispatcher les jobs (utilise la même logique de filtrage)
        $this->dispatchAgentIndexation($config);

        Notification::make()
            ->title('Indexation lancée')
            ->body("{$estimatedCount} URL(s) vont être indexées pour l'agent {$config->agent->name}.")
            ->success()
            ->send();
    }

    /**
     * Réindexe un agent (supprime les documents existants et relance l'indexation).
     */
    public function reindexAgent(int $configId): void
    {
        $config = AgentWebCrawl::find($configId);

        if (! $config || $config->web_crawl_id !== $this->record->id) {
            Notification::make()
                ->title('Erreur')
                ->body('Configuration non trouvée.')
                ->danger()
                ->send();

            return;
        }

        // Collecter les IDs des URLs du cache pour ce crawl
        $crawlUrlIds = $this->record->urlEntries()
            ->pluck('crawl_url_id')
            ->filter()
            ->toArray();

        // Supprimer les documents existants de cet agent pour ce crawl
        // 1. Via web_crawl_id (relation directe)
        // 2. Via crawl_url_id (fallback pour anciens documents sans web_crawl_id)
        // L'observer DocumentObserver gère la suppression de Qdrant et des chunks
        $documents = \App\Models\Document::where('agent_id', $config->agent_id)
            ->where(function ($query) use ($crawlUrlIds) {
                $query->where('web_crawl_id', $this->record->id)
                    ->orWhereIn('crawl_url_id', $crawlUrlIds);
            })
            ->get();

        foreach ($documents as $document) {
            $document->forceDelete();
        }

        // Supprimer les entrées d'indexation
        $config->urlEntries()->delete();

        // Réinitialiser les stats
        $config->update([
            'index_status' => 'pending',
            'pages_indexed' => 0,
            'pages_skipped' => 0,
            'pages_error' => 0,
            'last_indexed_at' => null,
        ]);

        // Lancer l'indexation
        $this->startAgentIndexation($configId);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('add_agent')
                ->label('Ajouter un agent')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('agent_id')
                        ->label('Agent')
                        ->options(
                            Agent::whereNotIn('id', $this->record->agents->pluck('id'))
                                ->pluck('name', 'id')
                        )
                        ->required()
                        ->searchable(),

                    Forms\Components\Radio::make('url_filter_mode')
                        ->label('Mode de filtrage')
                        ->options([
                            'exclude' => 'Exclure les patterns (indexe tout sauf les URLs matchant)',
                            'include' => 'Inclure uniquement (indexe seulement les URLs matchant)',
                        ])
                        ->default('exclude'),

                    Forms\Components\Textarea::make('url_patterns')
                        ->label('Patterns d\'URLs')
                        ->placeholder("/blog/*\n/products/*.html")
                        ->helperText('Un pattern par ligne. Vide = tout indexer')
                        ->rows(3),

                    Forms\Components\CheckboxList::make('content_types')
                        ->label('Types de contenu à indexer')
                        ->options([
                            'html' => 'HTML',
                            'pdf' => 'PDF',
                            'image' => 'Images',
                            'document' => 'Documents (Word, texte...)',
                        ])
                        ->default(['html', 'pdf', 'image', 'document']),

                    Forms\Components\Section::make('Langues à indexer')
                        ->description('Laissez vide pour indexer toutes les langues')
                        ->schema(self::getLocaleCheckboxesByContinent())
                        ->collapsed()
                        ->compact(),

                    Forms\Components\Select::make('chunk_strategy')
                        ->label('Stratégie de chunking')
                        ->options([
                            '' => 'Par défaut de l\'agent',
                            'simple' => 'Simple (découpage par taille)',
                            'html_semantic' => 'HTML Sémantique (balises)',
                            'llm_assisted' => 'LLM (découpage intelligent)',
                        ])
                        ->default(''),
                ])
                ->action(function (array $data) {
                    // Convertir les patterns
                    $patterns = $data['url_patterns']
                        ? array_filter(array_map('trim', explode("\n", $data['url_patterns'])))
                        : [];

                    // Créer la configuration agent-crawl
                    $agentConfig = AgentWebCrawl::create([
                        'agent_id' => $data['agent_id'],
                        'web_crawl_id' => $this->record->id,
                        'url_filter_mode' => $data['url_filter_mode'],
                        'url_patterns' => $patterns,
                        'content_types' => $data['content_types'],
                        'allowed_locales' => $data['allowed_locales'] ?? [],
                        'chunk_strategy' => $data['chunk_strategy'] ?: null,
                        'index_status' => 'pending',
                    ]);

                    // Compter les URLs qui correspondent aux filtres
                    $matchingCount = $this->countUrlsToIndex($agentConfig);

                    // Lancer l'indexation si des URLs correspondent
                    if ($matchingCount > 0) {
                        $agentConfig->update(['index_status' => 'indexing']);
                        $this->dispatchAgentIndexation($agentConfig);
                    }

                    $message = $matchingCount > 0
                        ? "L'indexation démarre sur {$matchingCount} URL(s) correspondant aux filtres."
                        : 'Aucune URL ne correspond aux filtres actuels. Modifiez les filtres ou attendez plus de données.';

                    Notification::make()
                        ->title('Agent ajouté')
                        ->body($message)
                        ->success()
                        ->send();
                }),

            Actions\Action::make('reset')
                ->label('Tout supprimer')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Supprimer toutes les données et recommencer')
                ->modalDescription('Cette action va supprimer toutes les URLs crawlées, les documents de tous les agents et les fichiers en cache.')
                ->modalSubmitActionLabel('Oui, tout supprimer')
                ->action(function () {
                    // Collecter les IDs des URLs du cache pour ce crawl
                    $crawlUrlIds = $this->record->urlEntries()
                        ->with('url')
                        ->get()
                        ->pluck('url.id')
                        ->filter()
                        ->toArray();

                    // Supprimer les fichiers en cache
                    foreach ($this->record->urlEntries()->with('url')->get() as $entry) {
                        if ($entry->url?->storage_path && Storage::disk('local')->exists($entry->url->storage_path)) {
                            Storage::disk('local')->delete($entry->url->storage_path);
                        }
                    }

                    // Supprimer les documents de tous les agents
                    // 1. Via web_crawl_id (relation directe)
                    // 2. Via crawl_url_id (fallback pour anciens documents sans web_crawl_id)
                    // L'observer gère la suppression de Qdrant et des chunks
                    $documents = \App\Models\Document::where('web_crawl_id', $this->record->id)
                        ->orWhereIn('crawl_url_id', $crawlUrlIds)
                        ->get();

                    foreach ($documents as $document) {
                        $document->forceDelete();
                    }

                    // Supprimer les entrées d'indexation par agent
                    foreach ($this->record->agentConfigs as $agentConfig) {
                        $agentConfig->urlEntries()->delete();
                        $agentConfig->update([
                            'index_status' => 'pending',
                            'pages_indexed' => 0,
                            'pages_skipped' => 0,
                            'pages_error' => 0,
                            'last_indexed_at' => null,
                        ]);
                    }

                    // Supprimer toutes les entrées d'URLs du cache
                    $this->record->urlEntries()->delete();

                    // Réinitialiser les compteurs
                    $this->record->update([
                        'status' => 'pending',
                        'pages_discovered' => 0,
                        'pages_crawled' => 0,
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

                    $message = 'Le crawl a démarré.';
                    if ($this->record->agentConfigs()->count() === 0) {
                        $message .= ' Vous pourrez ajouter des agents après le crawl pour indexer le contenu.';
                    }

                    Notification::make()
                        ->title('Crawl lancé')
                        ->body($message)
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
                ->modalDescription('Re-crawler le site, mettre à jour les pages existantes et réindexer tous les agents.')
                ->action(function () {
                    $this->record->update([
                        'status' => 'pending',
                        'pages_crawled' => 0,
                        'pages_discovered' => $this->record->urlEntries()->count(),
                        'started_at' => null,
                        'completed_at' => null,
                    ]);

                    $this->record->urlEntries()->update([
                        'status' => 'pending',
                        'error_message' => null,
                        'retry_count' => 0,
                    ]);

                    // Réinitialiser les stats des agents
                    foreach ($this->record->agentConfigs as $agentConfig) {
                        $agentConfig->update([
                            'index_status' => 'pending',
                            'pages_indexed' => 0,
                            'pages_skipped' => 0,
                            'pages_error' => 0,
                        ]);
                    }

                    StartWebCrawlJob::dispatch($this->record);

                    Notification::make()
                        ->title('Crawl relancé')
                        ->body('Le site va être re-crawlé et tous les agents réindexés.')
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

            Actions\Action::make('detect_locales')
                ->label('Détecter langues')
                ->icon('heroicon-o-language')
                ->color('info')
                ->modalHeading('Détecter les langues')
                ->modalDescription(function () {
                    $totalCount = WebCrawlUrl::query()
                        ->whereHas('crawls', fn ($q) => $q->where('web_crawls.id', $this->record->id))
                        ->whereNotNull('storage_path')
                        ->count();

                    $withoutLocale = WebCrawlUrl::query()
                        ->whereHas('crawls', fn ($q) => $q->where('web_crawls.id', $this->record->id))
                        ->whereNotNull('storage_path')
                        ->whereNull('locale')
                        ->count();

                    $withLocale = $totalCount - $withoutLocale;

                    return "Total: {$totalCount} documents • Sans langue: {$withoutLocale} • Avec langue: {$withLocale}";
                })
                ->form([
                    Forms\Components\Radio::make('mode')
                        ->label('Mode de détection')
                        ->options([
                            'new_only' => 'Nouveaux uniquement (documents sans langue détectée)',
                            'force_all' => 'Tout re-détecter (écrase les langues existantes)',
                        ])
                        ->default('new_only')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $forceAll = $data['mode'] === 'force_all';

                    $query = WebCrawlUrl::query()
                        ->whereHas('crawls', fn ($q) => $q->where('web_crawls.id', $this->record->id))
                        ->whereNotNull('storage_path');

                    if (!$forceAll) {
                        $query->whereNull('locale');
                    }

                    $count = $query->count();

                    if ($count === 0) {
                        Notification::make()
                            ->title('Aucun document à traiter')
                            ->body('Tous les documents ont déjà une langue détectée.')
                            ->info()
                            ->send();
                        return;
                    }

                    \App\Jobs\DetectCrawlUrlLocalesJob::dispatch($this->record, $forceAll);

                    $message = $forceAll
                        ? "Re-détection de la langue pour {$count} documents en arrière-plan."
                        : "Détection de la langue pour {$count} documents sans langue en arrière-plan.";

                    Notification::make()
                        ->title('Détection lancée')
                        ->body($message . ' Assurez-vous que le worker de queue est actif.')
                        ->success()
                        ->send();
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

                                TextEntry::make('domain')
                                    ->label('Domaine'),

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

                Section::make('Cache')
                    ->description('Statistiques du cache partagé')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('pages_discovered')
                                    ->label('Découvertes')
                                    ->numeric(),

                                TextEntry::make('pages_crawled')
                                    ->label('Crawlées')
                                    ->numeric(),

                                TextEntry::make('total_size_for_humans')
                                    ->label('Taille totale'),

                                TextEntry::make('progress_percent')
                                    ->label('Progression')
                                    ->suffix('%'),
                            ]),
                    ]),

                Section::make('Agents liés')
                    ->description('Chaque agent a sa propre configuration d\'indexation')
                    ->schema([
                        ViewEntry::make('agentConfigs')
                            ->label('')
                            ->view('filament.components.agent-crawl-list'),
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

                                TextEntry::make('paused_at')
                                    ->label('Pausé le')
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
                    ->with(['url'])
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
                    ->label('Cache')
                    ->colors([
                        'secondary' => 'pending',
                        'warning' => fn ($state) => in_array($state, ['fetching']),
                        'success' => 'fetched',
                        'danger' => 'error',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'En attente',
                        'fetching' => 'Téléchargement',
                        'fetched' => 'OK',
                        'error' => 'Erreur',
                        default => $state,
                    })
                    ->tooltip(fn ($record) => $record->error_message),

                Tables\Columns\TextColumn::make('url.http_status')
                    ->label('HTTP')
                    ->color(fn ($state) => match (true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 300 && $state < 400 => 'warning',
                        $state >= 400 => 'danger',
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('url.content_type')
                    ->label('Type')
                    ->limit(20),

                Tables\Columns\TextColumn::make('url.locale')
                    ->label('Langue')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? strtoupper($state) : '—')
                    ->color(fn ($state) => $state ? 'primary' : 'gray'),

                Tables\Columns\TextColumn::make('fetched_at')
                    ->label('Crawlé le')
                    ->dateTime('d/m H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut cache')
                    ->options([
                        'pending' => 'En attente',
                        'fetching' => 'Téléchargement',
                        'fetched' => 'OK',
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

                Tables\Filters\SelectFilter::make('locale')
                    ->label('Langue')
                    ->multiple()
                    ->options(fn () => $this->getLocaleOptionsForFilter())
                    ->query(function ($query, array $data) {
                        if (empty($data['values'])) {
                            return $query;
                        }

                        return $query->whereHas('url', fn ($q) => $q->whereIn('locale', $data['values']));
                    }),

                Tables\Filters\TernaryFilter::make('has_locale')
                    ->label('Langue détectée')
                    ->placeholder('Tous')
                    ->trueLabel('Avec langue')
                    ->falseLabel('Sans langue')
                    ->queries(
                        true: fn ($query) => $query->whereHas('url', fn ($q) => $q->whereNotNull('locale')),
                        false: fn ($query) => $query->whereHas('url', fn ($q) => $q->whereNull('locale')),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('view_cache')
                    ->label('Voir')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn ($record) => ! empty($record->url?->storage_path))
                    ->modalHeading(fn ($record) => 'Aperçu: ' . \Illuminate\Support\Str::limit($record->url?->url, 50))
                    ->modalContent(function ($record) {
                        $storagePath = $record->url->storage_path;

                        if (! Storage::disk('local')->exists($storagePath)) {
                            return view('filament.components.cached-content-missing', [
                                'url' => $record->url->url,
                                'storagePath' => $storagePath,
                            ]);
                        }

                        $content = Storage::disk('local')->get($storagePath);
                        $contentType = $record->url->content_type ?? 'text/plain';
                        $url = $record->url->url;
                        $isHtml = str_contains($contentType, 'text/html');

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
                    ->visible(fn ($record) => in_array($record->status, ['fetched', 'error']))
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        if ($this->record->isCompleted()) {
                            $this->record->update(['status' => 'running']);
                        }

                        $record->update([
                            'status' => 'pending',
                            'error_message' => null,
                            'retry_count' => 0,
                        ]);

                        CrawlUrlJob::dispatch($this->record, $record);

                        Notification::make()
                            ->title('Mise à jour lancée')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('view_url')
                    ->label('Ouvrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn ($record) => empty($record->url?->storage_path))
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
                            ->title("{$count} page(s) en mise à jour")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\BulkAction::make('redetect_locales')
                    ->label('Re-détecter la langue')
                    ->icon('heroicon-o-language')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Re-détecter la langue')
                    ->modalDescription(fn ($records) => "Re-détecter la langue pour {$records->count()} URL(s) sélectionnée(s). Les locales existantes seront écrasées.")
                    ->action(function ($records) {
                        $pdfExtractionMethod = $this->record->pdf_extraction_method ?? 'auto';
                        $detected = 0;
                        $failed = 0;

                        foreach ($records as $record) {
                            if (!$record->url?->storage_path) {
                                $failed++;
                                continue;
                            }

                            try {
                                // Force locale to null to re-detect
                                $record->url->update(['locale' => null]);
                                $locale = $record->url->detectAndSaveLocale($pdfExtractionMethod);

                                if ($locale) {
                                    $detected++;
                                } else {
                                    $failed++;
                                }
                            } catch (\Throwable $e) {
                                $failed++;
                            }
                        }

                        Notification::make()
                            ->title('Détection terminée')
                            ->body("{$detected} langue(s) détectée(s), {$failed} échec(s)")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100, 250, 500])
            ->defaultPaginationPageOption(50)
            ->poll('5s');
    }

    /**
     * Démarre l'indexation pour un agent config (appelé après ajout d'agent).
     */
    protected function dispatchAgentIndexation(AgentWebCrawl $agentConfig): void
    {
        // Pour chaque URL du cache, créer une entrée et dispatcher le job
        // UNIQUEMENT si l'URL passe les filtres de l'agent
        foreach ($this->record->urlEntries()->with('url')->get() as $entry) {
            if ($entry->status === 'fetched' && $entry->url?->storage_path) {
                $url = $entry->url;

                // Vérifier les filtres de l'agent AVANT de dispatcher le job
                if (!$agentConfig->shouldIndexUrl($url->url)) {
                    continue;
                }

                if ($url->content_type && !$agentConfig->shouldIndexContentType($url->content_type)) {
                    continue;
                }

                if (!$agentConfig->shouldIndexLocale($url->locale)) {
                    continue;
                }

                IndexAgentUrlJob::dispatch($agentConfig, $url);
            }
        }
    }

    /**
     * Compte le nombre d'URLs qui seront indexées après application des filtres.
     */
    protected function countUrlsToIndex(AgentWebCrawl $agentConfig): int
    {
        $count = 0;

        foreach ($this->record->urlEntries()->with('url')->get() as $entry) {
            if ($entry->status === 'fetched' && $entry->url?->storage_path) {
                $url = $entry->url;

                if (!$agentConfig->shouldIndexUrl($url->url)) {
                    continue;
                }

                if ($url->content_type && !$agentConfig->shouldIndexContentType($url->content_type)) {
                    continue;
                }

                if (!$agentConfig->shouldIndexLocale($url->locale)) {
                    continue;
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * Récupère les ressources en cache (CSS, images) pour une page HTML.
     */
    protected function getCachedResources(string $html, string $baseUrl): array
    {
        $resources = [];
        $urlNormalizer = app(UrlNormalizer::class);

        $parsedBase = parse_url($baseUrl);
        $baseScheme = $parsedBase['scheme'] ?? 'https';
        $baseHost = $parsedBase['host'] ?? '';
        $basePath = dirname($parsedBase['path'] ?? '/');

        $patterns = [
            '/<img[^>]+src=["\']([^"\']+)["\']/i',
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]*>/i',
            '/url\(["\']?([^"\')\s]+)["\']?\)/i',
        ];

        $foundUrls = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $resourceUrl) {
                    if (str_starts_with($resourceUrl, 'data:')) {
                        continue;
                    }

                    if (! str_starts_with($resourceUrl, 'http')) {
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

        foreach (array_unique($foundUrls) as $resourceUrl) {
            $urlHash = $urlNormalizer->hash($resourceUrl);

            $cachedUrl = WebCrawlUrl::where('url_hash', $urlHash)->first();

            if ($cachedUrl && $cachedUrl->storage_path && Storage::disk('local')->exists($cachedUrl->storage_path)) {
                $content = Storage::disk('local')->get($cachedUrl->storage_path);
                $mimeType = $cachedUrl->content_type ?? 'application/octet-stream';

                $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($content);

                $resources[$resourceUrl] = $dataUri;
            }
        }

        return $resources;
    }

    /**
     * Get locale options for the filter based on detected locales in this crawl.
     */
    protected function getLocaleOptionsForFilter(): array
    {
        $locales = WebCrawlUrl::query()
            ->whereHas('crawls', fn ($q) => $q->where('web_crawls.id', $this->record->id))
            ->whereNotNull('locale')
            ->distinct()
            ->pluck('locale')
            ->toArray();

        $detector = app(LanguageDetector::class);
        $options = [];

        foreach ($locales as $locale) {
            $options[$locale] = $detector->getLocaleName($locale) . ' (' . strtoupper($locale) . ')';
        }

        asort($options);

        return $options;
    }

    /**
     * Get locale checkboxes grouped by continent for the indexing form.
     */
    protected static function getLocaleCheckboxesByContinent(): array
    {
        $continents = LanguageDetector::getLocalesByContinent();
        $components = [];

        foreach ($continents as $continentKey => $continent) {
            $continentLocaleCodes = array_keys($continent['locales']);

            $components[] = Forms\Components\Section::make($continent['label'])
                ->schema([
                    Forms\Components\Toggle::make("select_all_{$continentKey}")
                        ->label('Tout sélectionner / désélectionner')
                        ->default(false)
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) use ($continentLocaleCodes, $continentKey) {
                            if ($state) {
                                $set("allowed_locales_{$continentKey}", $continentLocaleCodes);
                            } else {
                                $set("allowed_locales_{$continentKey}", []);
                            }
                        })
                        ->dehydrated(false),

                    Forms\Components\CheckboxList::make("allowed_locales_{$continentKey}")
                        ->label('')
                        ->options($continent['locales'])
                        ->default([])
                        ->afterStateHydrated(function ($state, Forms\Set $set, Forms\Get $get) use ($continentLocaleCodes, $continentKey) {
                            // Load from main allowed_locales
                            $mainState = $get('allowed_locales');
                            if (is_array($mainState) && !empty($mainState)) {
                                $filtered = array_intersect($mainState, $continentLocaleCodes);
                                $set("allowed_locales_{$continentKey}", array_values($filtered));
                            }
                        })
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) use ($continentLocaleCodes, $continentKey) {
                            // Update the select all toggle
                            $allSelected = count(array_intersect($state ?? [], $continentLocaleCodes)) === count($continentLocaleCodes);
                            $set("select_all_{$continentKey}", $allSelected);
                        })
                        ->columns(4),
                ])
                ->collapsed()
                ->compact();
        }

        // Add a hidden field to aggregate all selected locales
        $components[] = Forms\Components\Hidden::make('allowed_locales')
            ->dehydrateStateUsing(function ($state, Forms\Get $get) use ($continents) {
                // Aggregate all continent selections
                $allSelected = [];
                foreach ($continents as $continentKey => $continent) {
                    $continentSelection = $get("allowed_locales_{$continentKey}") ?? [];
                    $allSelected = array_merge($allSelected, $continentSelection);
                }
                return array_values(array_unique($allSelected));
            });

        return $components;
    }
}
