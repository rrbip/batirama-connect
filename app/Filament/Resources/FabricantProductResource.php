<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FabricantProductResource\Pages;
use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FabricantProductResource extends Resource
{
    protected static ?string $model = FabricantProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?string $modelLabel = 'Produit Fabricant';

    protected static ?string $pluralModelLabel = 'Produits Fabricants';

    protected static ?int $navigationSort = 16;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && (
            $user->hasRole('super-admin') ||
            $user->hasRole('admin') ||
            $user->hasRole('fabricant')
        );
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        // Les fabricants ne voient que les produits de leurs propres catalogues
        if ($user && $user->hasRole('fabricant') && !$user->hasRole('admin') && !$user->hasRole('super-admin')) {
            $query->whereHas('catalog', function ($q) use ($user) {
                $q->where('fabricant_id', $user->id);
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Product')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Informations')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\Section::make('Identification')
                                    ->schema([
                                        Forms\Components\Select::make('catalog_id')
                                            ->label('Catalogue')
                                            ->relationship('catalog', 'name', function ($query) {
                                                $user = auth()->user();
                                                // Les fabricants ne voient que leurs propres catalogues
                                                if ($user && $user->hasRole('fabricant') && !$user->hasRole('admin') && !$user->hasRole('super-admin')) {
                                                    $query->where('fabricant_id', $user->id);
                                                }
                                            })
                                            ->required()
                                            ->searchable()
                                            ->preload(),

                                        Forms\Components\TextInput::make('name')
                                            ->label('Nom du produit')
                                            ->required()
                                            ->maxLength(500),

                                        Forms\Components\TextInput::make('sku')
                                            ->label('SKU / Référence')
                                            ->maxLength(100),

                                        Forms\Components\TextInput::make('ean')
                                            ->label('Code EAN')
                                            ->maxLength(20),

                                        Forms\Components\TextInput::make('brand')
                                            ->label('Marque')
                                            ->maxLength(100),

                                        Forms\Components\TextInput::make('category')
                                            ->label('Catégorie')
                                            ->maxLength(255),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Description')
                                    ->schema([
                                        Forms\Components\Textarea::make('short_description')
                                            ->label('Description courte')
                                            ->rows(2)
                                            ->maxLength(500),

                                        Forms\Components\Textarea::make('description')
                                            ->label('Description complète')
                                            ->rows(4),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Prix & Stock')
                            ->icon('heroicon-o-currency-euro')
                            ->schema([
                                Forms\Components\Section::make('Prix')
                                    ->schema([
                                        Forms\Components\TextInput::make('price_ht')
                                            ->label('Prix HT')
                                            ->numeric()
                                            ->prefix('€')
                                            ->step(0.01),

                                        Forms\Components\TextInput::make('price_ttc')
                                            ->label('Prix TTC')
                                            ->numeric()
                                            ->prefix('€')
                                            ->step(0.01)
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->helperText('Calculé automatiquement'),

                                        Forms\Components\TextInput::make('tva_rate')
                                            ->label('Taux TVA')
                                            ->numeric()
                                            ->suffix('%')
                                            ->default(20),

                                        Forms\Components\TextInput::make('price_unit')
                                            ->label('Unité de prix')
                                            ->placeholder('m², kg, L, pièce...')
                                            ->maxLength(50),
                                    ])
                                    ->columns(4),

                                Forms\Components\Section::make('Disponibilité')
                                    ->schema([
                                        Forms\Components\Select::make('availability')
                                            ->label('Disponibilité')
                                            ->options([
                                                FabricantProduct::AVAILABILITY_IN_STOCK => 'En stock',
                                                FabricantProduct::AVAILABILITY_OUT_OF_STOCK => 'Rupture de stock',
                                                FabricantProduct::AVAILABILITY_ON_ORDER => 'Sur commande',
                                                FabricantProduct::AVAILABILITY_DISCONTINUED => 'Arrêté',
                                            ])
                                            ->default(FabricantProduct::AVAILABILITY_IN_STOCK),

                                        Forms\Components\TextInput::make('stock_quantity')
                                            ->label('Quantité en stock')
                                            ->numeric()
                                            ->minValue(0),

                                        Forms\Components\TextInput::make('min_order_quantity')
                                            ->label('Quantité min. commande')
                                            ->numeric()
                                            ->minValue(1),

                                        Forms\Components\TextInput::make('lead_time')
                                            ->label('Délai de livraison')
                                            ->placeholder('2-3 jours ouvrés')
                                            ->maxLength(100),
                                    ])
                                    ->columns(4),
                            ]),

                        Forms\Components\Tabs\Tab::make('Médias')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\Section::make('Images')
                                    ->schema([
                                        Forms\Components\TextInput::make('main_image_url')
                                            ->label('Image principale')
                                            ->url()
                                            ->maxLength(2048),

                                        Forms\Components\Repeater::make('images')
                                            ->label('Galerie d\'images')
                                            ->simple(
                                                Forms\Components\TextInput::make('url')
                                                    ->url()
                                                    ->required(),
                                            )
                                            ->addActionLabel('Ajouter une image')
                                            ->collapsible(),
                                    ]),

                                Forms\Components\Section::make('Documents')
                                    ->schema([
                                        Forms\Components\Repeater::make('documents')
                                            ->label('Fiches techniques, notices...')
                                            ->schema([
                                                Forms\Components\Select::make('type')
                                                    ->label('Type')
                                                    ->options([
                                                        'fiche_technique' => 'Fiche technique',
                                                        'notice' => 'Notice d\'utilisation',
                                                        'certificat' => 'Certificat',
                                                        'autre' => 'Autre',
                                                    ])
                                                    ->required(),

                                                Forms\Components\TextInput::make('url')
                                                    ->label('URL')
                                                    ->url()
                                                    ->required(),

                                                Forms\Components\TextInput::make('name')
                                                    ->label('Nom du fichier')
                                                    ->maxLength(100),
                                            ])
                                            ->columns(3)
                                            ->addActionLabel('Ajouter un document')
                                            ->collapsible(),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Spécifications')
                            ->icon('heroicon-o-clipboard-document-list')
                            ->schema([
                                Forms\Components\Section::make('Caractéristiques techniques')
                                    ->schema([
                                        Forms\Components\KeyValue::make('specifications')
                                            ->label('')
                                            ->keyLabel('Caractéristique')
                                            ->valueLabel('Valeur')
                                            ->addActionLabel('Ajouter une caractéristique'),
                                    ]),

                                Forms\Components\Section::make('Dimensions et poids')
                                    ->schema([
                                        Forms\Components\TextInput::make('weight_kg')
                                            ->label('Poids')
                                            ->numeric()
                                            ->suffix('kg')
                                            ->step(0.001),

                                        Forms\Components\TextInput::make('width_cm')
                                            ->label('Largeur')
                                            ->numeric()
                                            ->suffix('cm')
                                            ->step(0.01),

                                        Forms\Components\TextInput::make('height_cm')
                                            ->label('Hauteur')
                                            ->numeric()
                                            ->suffix('cm')
                                            ->step(0.01),

                                        Forms\Components\TextInput::make('depth_cm')
                                            ->label('Profondeur')
                                            ->numeric()
                                            ->suffix('cm')
                                            ->step(0.01),
                                    ])
                                    ->columns(4),
                            ]),

                        Forms\Components\Tabs\Tab::make('Statut & Marketplace')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Forms\Components\Section::make('Statut')
                                    ->schema([
                                        Forms\Components\Select::make('status')
                                            ->label('Statut')
                                            ->options([
                                                FabricantProduct::STATUS_ACTIVE => 'Actif',
                                                FabricantProduct::STATUS_INACTIVE => 'Inactif',
                                                FabricantProduct::STATUS_PENDING_REVIEW => 'En attente de validation',
                                                FabricantProduct::STATUS_ARCHIVED => 'Archivé',
                                            ])
                                            ->default(FabricantProduct::STATUS_PENDING_REVIEW)
                                            ->required(),

                                        Forms\Components\Toggle::make('is_verified')
                                            ->label('Vérifié')
                                            ->helperText('Les produits vérifiés ont été validés manuellement'),

                                        Forms\Components\Toggle::make('marketplace_visible')
                                            ->label('Visible sur le marketplace')
                                            ->default(true),
                                    ])
                                    ->columns(3),

                                Forms\Components\Section::make('Source')
                                    ->schema([
                                        Forms\Components\TextInput::make('source_url')
                                            ->label('URL source')
                                            ->url()
                                            ->disabled()
                                            ->dehydrated(false),

                                        Forms\Components\TextInput::make('extraction_method')
                                            ->label('Méthode d\'extraction')
                                            ->disabled()
                                            ->dehydrated(false),

                                        Forms\Components\TextInput::make('extraction_confidence')
                                            ->label('Confiance extraction')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->formatStateUsing(fn ($state) => $state ? round($state * 100) . '%' : '-'),

                                        Forms\Components\Placeholder::make('completeness')
                                            ->label('Score de complétude')
                                            ->content(fn ($record) => $record?->getCompletenessScore() . '%' ?? '-'),
                                    ])
                                    ->columns(4)
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
                Tables\Columns\ImageColumn::make('main_image_url')
                    ->label('')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://placehold.co/40x40?text=?'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->name)
                    ->icon(fn ($record) => $record->duplicate_of_id ? 'heroicon-o-document-duplicate' : null)
                    ->iconColor('warning'),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('locale')
                    ->label('Langue')
                    ->badge()
                    ->formatStateUsing(fn ($state) => strtoupper($state ?? '-'))
                    ->color(fn ($state) => match($state) {
                        'fr' => 'info',
                        'en' => 'success',
                        'de' => 'warning',
                        'bg' => 'danger',
                        'ru' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('catalog.name')
                    ->label('Catalogue')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('price_ht')
                    ->label('Prix HT')
                    ->money('EUR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('availability')
                    ->label('Dispo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        FabricantProduct::AVAILABILITY_IN_STOCK => 'Stock',
                        FabricantProduct::AVAILABILITY_OUT_OF_STOCK => 'Rupture',
                        FabricantProduct::AVAILABILITY_ON_ORDER => 'Commande',
                        FabricantProduct::AVAILABILITY_DISCONTINUED => 'Arrêté',
                        default => $state ?? '-',
                    })
                    ->color(fn ($state) => match($state) {
                        FabricantProduct::AVAILABILITY_IN_STOCK => 'success',
                        FabricantProduct::AVAILABILITY_OUT_OF_STOCK => 'danger',
                        FabricantProduct::AVAILABILITY_ON_ORDER => 'warning',
                        FabricantProduct::AVAILABILITY_DISCONTINUED => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        FabricantProduct::STATUS_ACTIVE => 'Actif',
                        FabricantProduct::STATUS_INACTIVE => 'Inactif',
                        FabricantProduct::STATUS_PENDING_REVIEW => 'En attente',
                        FabricantProduct::STATUS_ARCHIVED => 'Archivé',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        FabricantProduct::STATUS_ACTIVE => 'success',
                        FabricantProduct::STATUS_INACTIVE => 'gray',
                        FabricantProduct::STATUS_PENDING_REVIEW => 'warning',
                        FabricantProduct::STATUS_ARCHIVED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_verified')
                    ->label('Vérifié')
                    ->boolean(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Catégorie')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('brand')
                    ->label('Marque')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('catalog')
                    ->relationship('catalog', 'name')
                    ->label('Catalogue'),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        FabricantProduct::STATUS_ACTIVE => 'Actif',
                        FabricantProduct::STATUS_INACTIVE => 'Inactif',
                        FabricantProduct::STATUS_PENDING_REVIEW => 'En attente',
                        FabricantProduct::STATUS_ARCHIVED => 'Archivé',
                    ]),

                Tables\Filters\SelectFilter::make('availability')
                    ->label('Disponibilité')
                    ->options([
                        FabricantProduct::AVAILABILITY_IN_STOCK => 'En stock',
                        FabricantProduct::AVAILABILITY_OUT_OF_STOCK => 'Rupture',
                        FabricantProduct::AVAILABILITY_ON_ORDER => 'Sur commande',
                        FabricantProduct::AVAILABILITY_DISCONTINUED => 'Arrêté',
                    ]),

                Tables\Filters\TernaryFilter::make('is_verified')
                    ->label('Vérifié'),

                Tables\Filters\TernaryFilter::make('marketplace_visible')
                    ->label('Visible marketplace'),

                Tables\Filters\SelectFilter::make('locale')
                    ->label('Langue')
                    ->options(FabricantCatalog::getSupportedLocales()),

                Tables\Filters\Filter::make('no_locale')
                    ->label('Sans langue detectee')
                    ->query(fn ($query) => $query->whereNull('locale')),

                Tables\Filters\Filter::make('has_price')
                    ->label('Avec prix')
                    ->query(fn ($query) => $query->whereNotNull('price_ht')),

                Tables\Filters\Filter::make('has_image')
                    ->label('Avec image')
                    ->query(fn ($query) => $query->whereNotNull('main_image_url')),

                Tables\Filters\Filter::make('is_duplicate')
                    ->label('Est un doublon')
                    ->query(fn ($query) => $query->whereNotNull('duplicate_of_id')),

                Tables\Filters\Filter::make('has_duplicates')
                    ->label('A des doublons potentiels')
                    ->query(function ($query) {
                        // Products where SKU appears more than once in same catalog
                        return $query->whereIn('id', function ($subquery) {
                            $subquery->select('fp1.id')
                                ->from('fabricant_products as fp1')
                                ->join('fabricant_products as fp2', function ($join) {
                                    $join->on('fp1.catalog_id', '=', 'fp2.catalog_id')
                                        ->on('fp1.id', '!=', 'fp2.id')
                                        ->whereNull('fp2.deleted_at')
                                        ->where(function ($q) {
                                            $q->whereColumn('fp1.sku', 'fp2.sku')
                                                ->orWhereColumn('fp1.name', 'fp2.name')
                                                ->orWhere(function ($q2) {
                                                    $q2->whereColumn('fp1.source_hash', 'fp2.source_hash')
                                                        ->whereNotNull('fp1.source_hash');
                                                });
                                        });
                                })
                                ->whereNull('fp1.deleted_at');
                        });
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('verify')
                    ->label('Valider')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn ($record) => !$record->is_verified)
                    ->action(function (FabricantProduct $record) {
                        $record->verify();
                        Notification::make()
                            ->title('Produit validé')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('viewSource')
                    ->label('Voir source')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => $record->source_url, true)
                    ->visible(fn ($record) => $record->source_url !== null),

                Tables\Actions\Action::make('findDuplicates')
                    ->label('Doublons')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('warning')
                    ->action(function (FabricantProduct $record) {
                        $duplicates = $record->findPotentialDuplicates();

                        if ($duplicates->isEmpty()) {
                            Notification::make()
                                ->title('Aucun doublon')
                                ->body('Aucun doublon potentiel trouvé pour ce produit.')
                                ->success()
                                ->send();
                            return;
                        }

                        $list = $duplicates->take(5)->map(fn ($p) => "- {$p->name} (SKU: {$p->sku})")->join("\n");
                        $more = $duplicates->count() > 5 ? "\n... et " . ($duplicates->count() - 5) . " autres" : '';

                        Notification::make()
                            ->title($duplicates->count() . ' doublon(s) potentiel(s)')
                            ->body($list . $more)
                            ->warning()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('verify')
                        ->label('Valider')
                        ->icon('heroicon-o-check-badge')
                        ->action(fn ($records) => $records->each->verify())
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activer')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->update(['status' => FabricantProduct::STATUS_ACTIVE])),

                    Tables\Actions\BulkAction::make('detectLocale')
                        ->label('Detecter la langue')
                        ->icon('heroicon-o-language')
                        ->color('info')
                        ->action(function ($records) {
                            $detected = 0;
                            foreach ($records as $record) {
                                $locale = $record->detectLocale();
                                if ($locale) {
                                    $record->update(['locale' => $locale]);
                                    $detected++;
                                }
                            }

                            Notification::make()
                                ->title('Detection terminee')
                                ->body("Langue detectee pour {$detected} produit(s) sur " . $records->count())
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Désactiver')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->action(fn ($records) => $records->each->update(['status' => FabricantProduct::STATUS_INACTIVE])),

                    Tables\Actions\BulkAction::make('markAsDuplicates')
                        ->label('Marquer comme doublons')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Marquer comme doublons')
                        ->modalDescription('Le premier produit sélectionné sera conservé comme original. Les autres seront marqués comme doublons et archivés.')
                        ->action(function ($records) {
                            if ($records->count() < 2) {
                                Notification::make()
                                    ->title('Erreur')
                                    ->body('Sélectionnez au moins 2 produits pour marquer des doublons.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $original = $records->first();
                            $duplicates = $records->skip(1);

                            foreach ($duplicates as $duplicate) {
                                $duplicate->markAsDuplicateOf($original);
                            }

                            Notification::make()
                                ->title('Doublons marqués')
                                ->body($duplicates->count() . ' produit(s) marqué(s) comme doublons de "' . $original->name . '"')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFabricantProducts::route('/'),
            'create' => Pages\CreateFabricantProduct::route('/create'),
            'view' => Pages\ViewFabricantProduct::route('/{record}'),
            'edit' => Pages\EditFabricantProduct::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('status', FabricantProduct::STATUS_PENDING_REVIEW)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = static::getModel()::where('status', FabricantProduct::STATUS_PENDING_REVIEW)->count();
        return $count > 0 ? 'warning' : 'gray';
    }
}
