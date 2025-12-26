<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentCategoryResource\Pages;
use App\Models\DocumentCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DocumentCategoryResource extends Resource
{
    protected static ?string $model = DocumentCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $modelLabel = 'Catégorie de document';

    protected static ?string $pluralModelLabel = 'Catégories de documents';

    protected static ?int $navigationSort = 4;

    // Masqué - accessible via Gestion RAG
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) =>
                                $set('slug', Str::slug($state))
                            ),

                        Forms\Components\TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->helperText('Identifiant unique pour cette catégorie'),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->helperText('Description optionnelle pour aider l\'IA à choisir cette catégorie')
                            ->columnSpanFull(),

                        Forms\Components\ColorPicker::make('color')
                            ->label('Couleur')
                            ->default('#6B7280'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Statistiques')
                    ->schema([
                        Forms\Components\Placeholder::make('usage_count_display')
                            ->label('Nombre d\'utilisations')
                            ->content(fn (?DocumentCategory $record) => $record?->usage_count ?? 0),

                        Forms\Components\Placeholder::make('is_ai_generated_display')
                            ->label('Générée par IA')
                            ->content(fn (?DocumentCategory $record) => $record?->is_ai_generated ? 'Oui' : 'Non'),

                        Forms\Components\Placeholder::make('created_at_display')
                            ->label('Créée le')
                            ->content(fn (?DocumentCategory $record) => $record?->created_at?->format('d/m/Y H:i') ?? '-'),
                    ])
                    ->columns(3)
                    ->visible(fn (?DocumentCategory $record) => $record !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color')
                    ->label('')
                    ->width(20),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_ai_generated')
                    ->label('IA')
                    ->boolean()
                    ->trueIcon('heroicon-o-cpu-chip')
                    ->falseIcon('heroicon-o-user')
                    ->trueColor('info')
                    ->falseColor('gray')
                    ->tooltip(fn ($state) => $state ? 'Générée par IA' : 'Créée manuellement'),

                Tables\Columns\TextColumn::make('usage_count')
                    ->label('Utilisations')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state === 0 => 'gray',
                        $state < 10 => 'info',
                        $state < 50 => 'success',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_ai_generated')
                    ->label('Générée par IA')
                    ->placeholder('Toutes')
                    ->trueLabel('Générées par IA')
                    ->falseLabel('Créées manuellement'),

                Tables\Filters\Filter::make('unused')
                    ->label('Non utilisées')
                    ->query(fn ($query) => $query->where('usage_count', 0)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('recalculate')
                    ->label('Recalculer')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn (DocumentCategory $record) => $record->recalculateUsage())
                    ->tooltip('Recalculer le nombre d\'utilisations'),

                Tables\Actions\DeleteAction::make()
                    ->before(function (DocumentCategory $record) {
                        // Les chunks seront mis à NULL grâce à nullOnDelete
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('recalculate_all')
                        ->label('Recalculer les utilisations')
                        ->icon('heroicon-o-arrow-path')
                        ->action(fn ($records) => $records->each->recalculateUsage()),
                ]),
            ])
            ->defaultSort('usage_count', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentCategories::route('/'),
            'create' => Pages\CreateDocumentCategory::route('/create'),
            'edit' => Pages\EditDocumentCategory::route('/{record}/edit'),
        ];
    }
}
