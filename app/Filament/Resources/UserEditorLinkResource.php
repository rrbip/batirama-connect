<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserEditorLinkResource\Pages;
use App\Models\Role;
use App\Models\UserEditorLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserEditorLinkResource extends Resource
{
    protected static ?string $model = UserEditorLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?string $modelLabel = 'Lien Artisan-Éditeur';

    protected static ?string $pluralModelLabel = 'Liens Artisan-Éditeur';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Liaison')
                    ->schema([
                        Forms\Components\Select::make('artisan_id')
                            ->label('Artisan')
                            ->relationship('artisan', 'name', function (Builder $query) {
                                $artisanRole = Role::where('slug', 'artisan')->first();
                                if ($artisanRole) {
                                    $query->whereHas('roles', fn ($q) => $q->where('role_id', $artisanRole->id));
                                }
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Utilisateur avec le rôle Artisan'),

                        Forms\Components\Select::make('editor_id')
                            ->label('Éditeur')
                            ->relationship('editor', 'name', function (Builder $query) {
                                $editeurRole = Role::where('slug', 'editeur')->first();
                                if ($editeurRole) {
                                    $query->whereHas('roles', fn ($q) => $q->where('role_id', $editeurRole->id));
                                }
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Utilisateur avec le rôle Éditeur'),

                        Forms\Components\TextInput::make('external_id')
                            ->label('ID externe')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('DUR-001')
                            ->helperText('Identifiant de l\'artisan dans le système de l\'éditeur'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Actif')
                            ->default(true),

                        Forms\Components\DateTimePicker::make('linked_at')
                            ->label('Date de liaison')
                            ->default(now())
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Branding spécifique')
                    ->description('Personnalisation du branding pour cet artisan chez cet éditeur')
                    ->schema([
                        Forms\Components\TextInput::make('branding.welcome_message')
                            ->label('Message de bienvenue')
                            ->maxLength(255)
                            ->placeholder('Assistant EBP - Durant Peinture'),

                        Forms\Components\ColorPicker::make('branding.primary_color')
                            ->label('Couleur principale'),

                        Forms\Components\TextInput::make('branding.logo_url')
                            ->label('URL du logo')
                            ->url()
                            ->maxLength(500),

                        Forms\Components\TextInput::make('branding.signature')
                            ->label('Signature')
                            ->maxLength(100)
                            ->placeholder('Durant Peinture via EBP'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Permissions spécifiques')
                    ->description('Permissions de l\'artisan chez cet éditeur')
                    ->schema([
                        Forms\Components\Toggle::make('permissions.can_create_sessions')
                            ->label('Peut créer des sessions')
                            ->default(true),

                        Forms\Components\Toggle::make('permissions.can_view_analytics')
                            ->label('Peut voir les analytics')
                            ->default(false),

                        Forms\Components\TextInput::make('permissions.max_sessions_month')
                            ->label('Max sessions/mois')
                            ->numeric()
                            ->placeholder('Illimité')
                            ->helperText('Quota mensuel de sessions pour cet artisan'),
                    ])
                    ->columns(3)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('artisan.name')
                    ->label('Artisan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('artisan.company_name')
                    ->label('Entreprise')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('editor.name')
                    ->label('Éditeur')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('external_id')
                    ->label('ID externe')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('sessions_count')
                    ->label('Sessions')
                    ->counts('sessions')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('linked_at')
                    ->label('Lié le')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('editor')
                    ->relationship('editor', 'name')
                    ->label('Éditeur'),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Actif'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activer')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->update(['is_active' => true])),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Désactiver')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->action(fn ($records) => $records->each->update(['is_active' => false])),
                ]),
            ])
            ->defaultSort('linked_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserEditorLinks::route('/'),
            'create' => Pages\CreateUserEditorLink::route('/create'),
            'view' => Pages\ViewUserEditorLink::route('/{record}'),
            'edit' => Pages\EditUserEditorLink::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('is_active', true)->count();
    }
}
