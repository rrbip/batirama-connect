<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FabricantCatalogResource\Pages;
use App\Models\FabricantCatalog;
use App\Models\Role;
use App\Models\WebCrawl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FabricantCatalogResource extends Resource
{
    protected static ?string $model = FabricantCatalog::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?string $modelLabel = 'Catalogue Fabricant';

    protected static ?string $pluralModelLabel = 'Catalogues Fabricants';

    protected static ?int $navigationSort = 15;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && (
            $user->hasRole('super-admin') ||
            $user->hasRole('admin') ||
            $user->hasRole('fabricant')
        );
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        // Les fabricants ne voient que leurs propres catalogues
        if ($user && $user->hasRole('fabricant') && !$user->hasRole('admin') && !$user->hasRole('super-admin')) {
            $query->where('fabricant_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Catalog')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Informations')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\Section::make('Identité du catalogue')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Nom du catalogue')
                                            ->required()
                                            ->maxLength(255)
                                            ->placeholder('Catalogue Weber 2024')
                                            ->helperText('Nom descriptif pour identifier ce catalogue'),

                                        Forms\Components\Select::make('fabricant_id')
                                            ->label('Fabricant')
                                            ->relationship('fabricant', 'name', function (Builder $query) {
                                                $fabricantRole = Role::where('slug', 'fabricant')->first();
                                                if ($fabricantRole) {
                                                    $query->whereHas('roles', fn ($q) => $q->where('role_id', $fabricantRole->id));
                                                }
                                            })
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->helperText('Utilisateur avec le rôle Fabricant')
                                            // Pour les fabricants: auto-assigner et masquer
                                            ->default(function () {
                                                $user = auth()->user();
                                                if ($user && $user->hasRole('fabricant') && !$user->hasRole('admin') && !$user->hasRole('super-admin')) {
                                                    return $user->id;
                                                }
                                                return null;
                                            })
                                            ->disabled(function () {
                                                $user = auth()->user();
                                                return $user && $user->hasRole('fabricant') && !$user->hasRole('admin') && !$user->hasRole('super-admin');
                                            })
                                            ->dehydrated(true),

                                        Forms\Components\TextInput::make('website_url')
                                            ->label('URL du site web')
                                            ->required()
                                            ->url()
                                            ->maxLength(2048)
                                            ->placeholder('https://www.weber.fr')
                                            ->helperText('Page d\'accueil ou page catalogue du fabricant'),

                                        Forms\Components\Textarea::make('description')
                                            ->label('Description')
                                            ->rows(2)
                                            ->maxLength(1000)
                                            ->placeholder('Catalogue des produits Weber - mortiers, enduits, colles...'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Planification')
                                    ->schema([
                                        Forms\Components\Select::make('refresh_frequency')
                                            ->label('Fréquence de mise à jour')
                                            ->options([
                                                FabricantCatalog::REFRESH_MANUAL => 'Manuel uniquement',
                                                FabricantCatalog::REFRESH_DAILY => 'Quotidien',
                                                FabricantCatalog::REFRESH_WEEKLY => 'Hebdomadaire',
                                                FabricantCatalog::REFRESH_MONTHLY => 'Mensuel',
                                            ])
                                            ->default(FabricantCatalog::REFRESH_WEEKLY)
                                            ->helperText('À quelle fréquence recrawler et extraire les produits'),

                                        Forms\Components\DateTimePicker::make('next_refresh_at')
                                            ->label('Prochain refresh programmé')
                                            ->disabled()
                                            ->visible(fn ($record) => $record !== null),
                                    ])
                                    ->columns(2),
                            ]),

                        Forms\Components\Tabs\Tab::make('Configuration extraction')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Forms\Components\Section::make('Patterns URL produits')
                                    ->description('Patterns pour identifier les pages produits à extraire. Utilisez * comme wildcard.')
                                    ->schema([
                                        Forms\Components\Repeater::make('extraction_config.product_url_patterns')
                                            ->label('Patterns URL')
                                            ->simple(
                                                Forms\Components\TextInput::make('pattern')
                                                    ->placeholder('*/produit/*')
                                                    ->required(),
                                            )
                                            ->default([
                                                '*/produit/*',
                                                '*/fiche-technique/*',
                                                '*/product/*',
                                            ])
                                            ->addActionLabel('Ajouter un pattern')
                                            ->collapsible(),
                                    ]),

                                Forms\Components\Section::make('Extraction LLM')
                                    ->schema([
                                        Forms\Components\Toggle::make('extraction_config.use_llm_extraction')
                                            ->label('Utiliser l\'IA pour l\'extraction')
                                            ->default(true)
                                            ->helperText('L\'IA analyse les pages pour extraire les métadonnées produit si les sélecteurs CSS ne suffisent pas'),
                                    ]),

                                Forms\Components\Section::make('Sélecteurs CSS (optionnel)')
                                    ->description('Sélecteurs CSS pour l\'extraction directe. Laissez vide pour utiliser les valeurs par défaut.')
                                    ->schema([
                                        Forms\Components\TextInput::make('extraction_config.selectors.name')
                                            ->label('Nom du produit')
                                            ->placeholder('h1, .product-title'),

                                        Forms\Components\TextInput::make('extraction_config.selectors.price')
                                            ->label('Prix')
                                            ->placeholder('.price, [itemprop="price"]'),

                                        Forms\Components\TextInput::make('extraction_config.selectors.sku')
                                            ->label('SKU / Référence')
                                            ->placeholder('.sku, .reference'),

                                        Forms\Components\TextInput::make('extraction_config.selectors.description')
                                            ->label('Description')
                                            ->placeholder('.description'),

                                        Forms\Components\TextInput::make('extraction_config.selectors.image')
                                            ->label('Images')
                                            ->placeholder('.product-gallery img'),

                                        Forms\Components\TextInput::make('extraction_config.selectors.specs')
                                            ->label('Spécifications')
                                            ->placeholder('.specifications table'),
                                    ])
                                    ->columns(2)
                                    ->collapsed(),

                                Forms\Components\Section::make('Detection de la langue')
                                    ->description('Configuration de la detection automatique de la langue des produits.')
                                    ->schema([
                                        Forms\Components\Toggle::make('extraction_config.locale_detection.enabled')
                                            ->label('Activer la detection de langue')
                                            ->default(true)
                                            ->live()
                                            ->helperText('Detecte automatiquement la langue des produits pour distinguer les variantes linguistiques'),

                                        Forms\Components\Grid::make(3)
                                            ->schema([
                                                Forms\Components\Toggle::make('extraction_config.locale_detection.methods.url')
                                                    ->label('Detection par URL')
                                                    ->default(true)
                                                    ->helperText('/fr/, /en/, /de/...'),

                                                Forms\Components\Toggle::make('extraction_config.locale_detection.methods.sku')
                                                    ->label('Detection par SKU')
                                                    ->default(true)
                                                    ->helperText('-FR, -EN, _DE...'),

                                                Forms\Components\Toggle::make('extraction_config.locale_detection.methods.content')
                                                    ->label('Detection par contenu')
                                                    ->default(true)
                                                    ->helperText('Analyse des mots frequents'),
                                            ])
                                            ->visible(fn ($get) => $get('extraction_config.locale_detection.enabled')),

                                        Forms\Components\Fieldset::make('Langues à détecter')
                                            ->schema(self::getLocaleCheckboxesByContinent())
                                            ->visible(fn ($get) => $get('extraction_config.locale_detection.enabled'))
                                            ->columnSpanFull(),

                                        Forms\Components\Select::make('extraction_config.locale_detection.default_locale')
                                            ->label('Forcer une langue par defaut')
                                            ->options(array_merge(['' => '-- Aucune (detection auto) --'], FabricantCatalog::getSupportedLocales()))
                                            ->default(null)
                                            ->visible(fn ($get) => $get('extraction_config.locale_detection.enabled'))
                                            ->helperText('Si definie, tous les produits auront cette langue (ignore la detection)'),
                                    ])
                                    ->collapsible(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Web Crawl')
                            ->icon('heroicon-o-globe-alt')
                            ->visible(fn ($record) => $record !== null)
                            ->schema([
                                Forms\Components\Section::make('Crawl associé')
                                    ->schema([
                                        Forms\Components\Select::make('web_crawl_id')
                                            ->label('Web Crawl')
                                            ->options(function ($record) {
                                                // Show crawls from this catalog's website
                                                return WebCrawl::where('start_url', 'LIKE', '%' . parse_url($record?->website_url ?? '', PHP_URL_HOST) . '%')
                                                    ->orWhere('id', $record?->web_crawl_id)
                                                    ->pluck('start_url', 'id')
                                                    ->map(fn ($url) => substr($url, 0, 60) . '...');
                                            })
                                            ->searchable()
                                            ->helperText('Associer un crawl existant ou créer un nouveau crawl'),

                                        Forms\Components\Placeholder::make('crawl_status')
                                            ->label('Statut du crawl')
                                            ->content(fn ($record) => $record?->webCrawl?->status ?? 'Aucun crawl'),

                                        Forms\Components\Placeholder::make('crawl_pages')
                                            ->label('Pages crawlées')
                                            ->content(fn ($record) => $record?->webCrawl?->pages_crawled ?? 0),
                                    ])
                                    ->columns(3),
                            ]),

                        Forms\Components\Tabs\Tab::make('Statistiques')
                            ->icon('heroicon-o-chart-bar')
                            ->visible(fn ($record) => $record !== null)
                            ->schema([
                                Forms\Components\Section::make('État actuel')
                                    ->schema([
                                        Forms\Components\Placeholder::make('status_display')
                                            ->label('Statut')
                                            ->content(fn ($record) => match($record?->status) {
                                                FabricantCatalog::STATUS_PENDING => '⏳ En attente',
                                                FabricantCatalog::STATUS_CRAWLING => '🔄 Crawl en cours',
                                                FabricantCatalog::STATUS_EXTRACTING => '⚙️ Extraction en cours',
                                                FabricantCatalog::STATUS_COMPLETED => '✅ Terminé',
                                                FabricantCatalog::STATUS_FAILED => '❌ Échec',
                                                default => $record?->status ?? '-',
                                            }),

                                        Forms\Components\Placeholder::make('products_count')
                                            ->label('Produits trouvés')
                                            ->content(fn ($record) => $record?->products_found ?? 0),

                                        Forms\Components\Placeholder::make('products_updated')
                                            ->label('Produits mis à jour')
                                            ->content(fn ($record) => $record?->products_updated ?? 0),

                                        Forms\Components\Placeholder::make('products_failed')
                                            ->label('Échecs')
                                            ->content(fn ($record) => $record?->products_failed ?? 0),
                                    ])
                                    ->columns(4),

                                Forms\Components\Section::make('Historique')
                                    ->schema([
                                        Forms\Components\Placeholder::make('last_crawl_display')
                                            ->label('Dernier crawl')
                                            ->content(fn ($record) => $record?->last_crawl_at?->format('d/m/Y H:i') ?? 'Jamais'),

                                        Forms\Components\Placeholder::make('last_extraction_display')
                                            ->label('Dernière extraction')
                                            ->content(fn ($record) => $record?->last_extraction_at?->format('d/m/Y H:i') ?? 'Jamais'),

                                        Forms\Components\Placeholder::make('last_error_display')
                                            ->label('Dernière erreur')
                                            ->content(fn ($record) => $record?->last_error ?? 'Aucune')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Produits actifs')
                                    ->schema([
                                        Forms\Components\Placeholder::make('stats_display')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (!$record) return '-';
                                                $stats = $record->getStats();
                                                return sprintf(
                                                    "Total: %d | Actifs: %d | En attente: %d | Vérifiés: %d | Avec prix: %d | Avec images: %d",
                                                    $stats['total_products'],
                                                    $stats['active_products'],
                                                    $stats['pending_review'],
                                                    $stats['verified_products'],
                                                    $stats['with_price'],
                                                    $stats['with_images']
                                                );
                                            }),
                                    ]),

                                Forms\Components\Section::make('Détection des langues')
                                    ->schema([
                                        Forms\Components\Placeholder::make('locale_stats_display')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (!$record) return '-';

                                                $total = $record->products()->count();
                                                if ($total === 0) return 'Aucun produit';

                                                $withLocale = $record->products()->whereNotNull('locale')->count();
                                                $withoutLocale = $total - $withLocale;

                                                $localeStats = $record->products()
                                                    ->whereNotNull('locale')
                                                    ->selectRaw('locale, COUNT(*) as cnt')
                                                    ->groupBy('locale')
                                                    ->pluck('cnt', 'locale')
                                                    ->toArray();

                                                $lines = [];
                                                $lines[] = sprintf(
                                                    "Langue détectée: %d / %d (%.0f%%)",
                                                    $withLocale,
                                                    $total,
                                                    $total > 0 ? ($withLocale / $total * 100) : 0
                                                );

                                                if (!empty($localeStats)) {
                                                    $breakdown = [];
                                                    $localeNames = \App\Models\FabricantCatalog::getSupportedLocales();
                                                    foreach ($localeStats as $locale => $count) {
                                                        $name = $localeNames[$locale] ?? strtoupper($locale);
                                                        $breakdown[] = "{$name}: {$count}";
                                                    }
                                                    $lines[] = implode(' | ', $breakdown);
                                                }

                                                if ($withoutLocale > 0) {
                                                    $lines[] = "⚠️ {$withoutLocale} produit(s) sans langue détectée";
                                                }

                                                return implode("\n", $lines);
                                            }),
                                    ])
                                    ->visible(fn ($record) => $record !== null),

                                Forms\Components\Section::make('Doublons & Variantes linguistiques')
                                    ->schema([
                                        Forms\Components\Placeholder::make('duplicate_stats_display')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (!$record) return '-';
                                                $stats = \App\Models\FabricantProduct::getDuplicateStats($record->id);
                                                if ($stats['total_products'] === 0) {
                                                    return 'Aucun produit';
                                                }

                                                $lines = [];

                                                // Language variants info
                                                if (($stats['language_variants'] ?? 0) > 0) {
                                                    $lines[] = "Variantes linguistiques: {$stats['language_variants']} produits en plusieurs langues";
                                                }

                                                // Duplicates
                                                $duplicateCount = $stats['duplicate_sku_products'] + $stats['duplicate_name_products'] + $stats['duplicate_hash_products'];
                                                if ($duplicateCount === 0) {
                                                    $lines[] = 'Aucun doublon détecté';
                                                } else {
                                                    $lines[] = sprintf(
                                                        "Doublons SKU: %d | Doublons Nom (même langue): %d | Doublons Hash: %d",
                                                        $stats['duplicate_sku_products'],
                                                        $stats['duplicate_name_products'],
                                                        $stats['duplicate_hash_products']
                                                    );
                                                }

                                                return implode("\n", $lines);
                                            }),
                                    ])
                                    ->visible(fn ($record) => $record !== null),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('fabricant.name')
                    ->label('Fabricant')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('website_url')
                    ->label('Site web')
                    ->limit(30)
                    ->url(fn ($record) => $record->website_url, true)
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        FabricantCatalog::STATUS_PENDING => 'En attente',
                        FabricantCatalog::STATUS_CRAWLING => 'Crawl...',
                        FabricantCatalog::STATUS_EXTRACTING => 'Extraction...',
                        FabricantCatalog::STATUS_COMPLETED => 'Terminé',
                        FabricantCatalog::STATUS_FAILED => 'Échec',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        FabricantCatalog::STATUS_PENDING => 'gray',
                        FabricantCatalog::STATUS_CRAWLING => 'warning',
                        FabricantCatalog::STATUS_EXTRACTING => 'warning',
                        FabricantCatalog::STATUS_COMPLETED => 'success',
                        FabricantCatalog::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('products_found')
                    ->label('Produits')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('refresh_frequency')
                    ->label('Refresh')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        FabricantCatalog::REFRESH_MANUAL => 'Manuel',
                        FabricantCatalog::REFRESH_DAILY => 'Quotidien',
                        FabricantCatalog::REFRESH_WEEKLY => 'Hebdo',
                        FabricantCatalog::REFRESH_MONTHLY => 'Mensuel',
                        default => $state ?? '-',
                    })
                    ->color('gray'),

                Tables\Columns\TextColumn::make('last_extraction_at')
                    ->label('Dernière extraction')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('fabricant')
                    ->relationship('fabricant', 'name')
                    ->label('Fabricant'),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        FabricantCatalog::STATUS_PENDING => 'En attente',
                        FabricantCatalog::STATUS_CRAWLING => 'Crawl en cours',
                        FabricantCatalog::STATUS_EXTRACTING => 'Extraction en cours',
                        FabricantCatalog::STATUS_COMPLETED => 'Terminé',
                        FabricantCatalog::STATUS_FAILED => 'Échec',
                    ]),

                Tables\Filters\SelectFilter::make('refresh_frequency')
                    ->label('Fréquence')
                    ->options([
                        FabricantCatalog::REFRESH_MANUAL => 'Manuel',
                        FabricantCatalog::REFRESH_DAILY => 'Quotidien',
                        FabricantCatalog::REFRESH_WEEKLY => 'Hebdomadaire',
                        FabricantCatalog::REFRESH_MONTHLY => 'Mensuel',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('startCrawl')
                    ->label('Lancer crawl')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn ($record) => !$record->isProcessing())
                    ->requiresConfirmation()
                    ->modalDescription('Lancer un nouveau crawl du site fabricant pour extraire les produits ?')
                    ->action(function (FabricantCatalog $record) {
                        // Create or reuse web crawl - unlimited depth/pages for fabricant catalogs
                        $crawl = WebCrawl::create([
                            'start_url' => $record->website_url,
                            'allowed_domains' => [parse_url($record->website_url, PHP_URL_HOST)],
                            'max_depth' => 0,       // 0 = illimité
                            'max_pages' => 0,       // 0 = illimité
                            'respect_robots_txt' => true,
                            'delay_ms' => 1000,
                            'status' => 'pending',
                        ]);

                        $record->update([
                            'web_crawl_id' => $crawl->id,
                        ]);
                        $record->markAsCrawling();

                        // Dispatch crawl job
                        \App\Jobs\Crawler\StartWebCrawlJob::dispatch($crawl);

                        Notification::make()
                            ->title('Crawl démarré')
                            ->body('Le crawl du site fabricant a été lancé.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('extractProducts')
                    ->label('Extraire produits')
                    ->icon('heroicon-o-cpu-chip')
                    ->color('warning')
                    ->visible(fn ($record) => $record->webCrawl !== null && $record->status !== FabricantCatalog::STATUS_EXTRACTING)
                    ->requiresConfirmation()
                    ->action(function (FabricantCatalog $record) {
                        // Check if there are pages to extract
                        $crawl = $record->webCrawl;

                        if ($crawl->status !== 'completed' && $crawl->status !== 'paused') {
                            Notification::make()
                                ->title('Crawl non terminé')
                                ->body("Le crawl est en statut \"{$crawl->status}\". Attendez qu'il soit terminé avant d'extraire les produits.")
                                ->warning()
                                ->send();
                            return;
                        }

                        $eligiblePages = $crawl->urls()
                            ->where('http_status', 200)
                            ->where('content_type', 'LIKE', 'text/html%')
                            ->wherePivot('status', 'fetched')
                            ->whereNotNull('storage_path')
                            ->count();

                        if ($eligiblePages === 0) {
                            Notification::make()
                                ->title('Aucune page à extraire')
                                ->body('Le crawl ne contient aucune page HTML valide (status 200, contenu stocké). Vérifiez que le crawl a bien fonctionné.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Dispatch extraction job
                        \App\Jobs\ExtractFabricantProductsJob::dispatch($record);

                        Notification::make()
                            ->title('Extraction démarrée')
                            ->body("Analyse de {$eligiblePages} pages HTML en cours...")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('detectLocales')
                    ->label('Détecter langues')
                    ->icon('heroicon-o-language')
                    ->color('info')
                    ->visible(fn ($record) => $record->products()->whereNull('locale')->exists())
                    ->form([
                        Forms\Components\Toggle::make('run_sync')
                            ->label('Exécuter maintenant (synchrone)')
                            ->helperText('Décochez pour utiliser la queue en arrière-plan (nécessite un worker actif).')
                            ->default(false),
                    ])
                    ->modalHeading('Détecter les langues')
                    ->modalDescription('Lancer la détection automatique de langue pour tous les produits sans langue détectée ?')
                    ->action(function (FabricantCatalog $record, array $data) {
                        $count = $record->products()->whereNull('locale')->count();

                        if ($count === 0) {
                            Notification::make()
                                ->title('Aucun produit à traiter')
                                ->body('Tous les produits ont déjà une langue détectée.')
                                ->info()
                                ->send();
                            return;
                        }

                        $runSync = $data['run_sync'] ?? false;

                        if ($runSync) {
                            // Run synchronously
                            try {
                                \App\Jobs\DetectProductLocalesJob::dispatchSync($record);

                                $detected = $record->products()->whereNotNull('locale')->count();
                                $remaining = $record->products()->whereNull('locale')->count();

                                $message = "Langue détectée pour {$detected} produits.";
                                if ($remaining > 0) {
                                    $message .= " {$remaining} produits n'ont pas pu être détectés (pas de contenu HTML ou langue inconnue).";
                                }

                                Notification::make()
                                    ->title('Détection terminée')
                                    ->body($message)
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('Erreur lors de la détection')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        } else {
                            // Dispatch to queue
                            \App\Jobs\DetectProductLocalesJob::dispatch($record);

                            Notification::make()
                                ->title('Détection lancée en arrière-plan')
                                ->body("Détection de la langue pour {$count} produits. Assurez-vous que le worker de queue est actif (php artisan queue:work).")
                                ->success()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('viewProducts')
                    ->label('Voir produits')
                    ->icon('heroicon-o-shopping-bag')
                    ->url(fn ($record) => FabricantProductResource::getUrl('index', [
                        'tableFilters[catalog][value]' => $record->id,
                    ])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            // Could add a RelationManager for products
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFabricantCatalogs::route('/'),
            'create' => Pages\CreateFabricantCatalog::route('/create'),
            'view' => Pages\ViewFabricantCatalog::route('/{record}'),
            'edit' => Pages\EditFabricantCatalog::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    /**
     * Generate checkbox lists for locales grouped by continent.
     */
    private static function getLocaleCheckboxesByContinent(): array
    {
        $continents = \App\Services\Marketplace\LanguageDetector::getLocalesByContinent();
        $allLocales = FabricantCatalog::getSupportedLocales();
        $allLocaleKeys = array_keys($allLocales);

        $components = [];

        foreach ($continents as $continentKey => $continent) {
            // Get locale codes for this continent
            $continentLocaleCodes = array_keys($continent['locales']);

            $components[] = Forms\Components\Section::make($continent['label'])
                ->schema([
                    Forms\Components\Toggle::make("select_all_{$continentKey}")
                        ->label('Tout sélectionner / désélectionner')
                        ->default(true)
                        ->live()
                        ->afterStateHydrated(function ($state, $set, $get) use ($continentLocaleCodes, $continentKey) {
                            // Check if all locales of this continent are selected
                            $selected = $get("extraction_config.locale_detection.allowed_locales_{$continentKey}") ?? [];
                            $allSelected = count(array_intersect($selected, $continentLocaleCodes)) === count($continentLocaleCodes);
                            $set("select_all_{$continentKey}", $allSelected);
                        })
                        ->afterStateUpdated(function ($state, $set) use ($continentLocaleCodes, $continentKey) {
                            if ($state) {
                                // Select all
                                $set("extraction_config.locale_detection.allowed_locales_{$continentKey}", $continentLocaleCodes);
                            } else {
                                // Deselect all
                                $set("extraction_config.locale_detection.allowed_locales_{$continentKey}", []);
                            }
                        })
                        ->dehydrated(false),

                    Forms\Components\CheckboxList::make("extraction_config.locale_detection.allowed_locales_{$continentKey}")
                        ->label('')
                        ->options($continent['locales'])
                        ->default($continentLocaleCodes)
                        ->afterStateHydrated(function ($state, $set, $get) use ($continentLocaleCodes, $continentKey) {
                            // Check main state for allowed_locales
                            $mainState = $get('extraction_config.locale_detection.allowed_locales');
                            if ($mainState === null || (is_array($mainState) && empty($mainState))) {
                                // All selected by default for new records
                                $set("extraction_config.locale_detection.allowed_locales_{$continentKey}", $continentLocaleCodes);
                            } elseif (is_array($mainState)) {
                                // Filter to only this continent's locales
                                $filtered = array_intersect($mainState, $continentLocaleCodes);
                                $set("extraction_config.locale_detection.allowed_locales_{$continentKey}", array_values($filtered));
                            }
                        })
                        ->live()
                        ->afterStateUpdated(function ($state, $set, $get) use ($continentLocaleCodes, $continentKey) {
                            // Update the select all toggle based on selection
                            $allSelected = count(array_intersect($state ?? [], $continentLocaleCodes)) === count($continentLocaleCodes);
                            $set("select_all_{$continentKey}", $allSelected);
                        })
                        ->columns(4),
                ])
                ->collapsed()
                ->compact();
        }

        // Add a hidden field to aggregate all selected locales
        $components[] = Forms\Components\Hidden::make('extraction_config.locale_detection.allowed_locales')
            ->afterStateHydrated(function ($state, $set) use ($allLocaleKeys) {
                if ($state === null || (is_array($state) && empty($state))) {
                    $set('extraction_config.locale_detection.allowed_locales', $allLocaleKeys);
                }
            })
            ->dehydrateStateUsing(function ($state, $get) use ($continents) {
                // Aggregate all continent selections
                $allSelected = [];
                foreach ($continents as $continentKey => $continent) {
                    $continentSelection = $get("extraction_config.locale_detection.allowed_locales_{$continentKey}") ?? [];
                    $allSelected = array_merge($allSelected, $continentSelection);
                }
                // Return empty array if all are selected (means all available)
                $allLocaleKeys = array_keys(FabricantCatalog::getSupportedLocales());
                if (count($allSelected) === count($allLocaleKeys)) {
                    return []; // Empty = all locales (dynamic)
                }
                return array_values(array_unique($allSelected));
            });

        return $components;
    }
}
