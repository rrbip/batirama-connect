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
                                            ->helperText('Utilisateur avec le rôle Fabricant'),

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
                        // Create or reuse web crawl
                        $crawl = WebCrawl::create([
                            'start_url' => $record->website_url,
                            'allowed_domains' => [parse_url($record->website_url, PHP_URL_HOST)],
                            'max_depth' => 5,
                            'max_pages' => 1000,
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
                        // Dispatch extraction job
                        \App\Jobs\ExtractFabricantProductsJob::dispatch($record);

                        Notification::make()
                            ->title('Extraction démarrée')
                            ->body('L\'extraction des produits est en cours.')
                            ->success()
                            ->send();
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
}
