<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $modelLabel = 'Utilisateur';

    protected static ?string $pluralModelLabel = 'Utilisateurs';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('User')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Informations')
                            ->icon('heroicon-o-user')
                            ->schema([
                                Forms\Components\Section::make('Informations personnelles')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Nom')
                                            ->required()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('email')
                                            ->label('Email')
                                            ->email()
                                            ->required()
                                            ->unique(ignoreRecord: true)
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('password')
                                            ->label('Mot de passe')
                                            ->password()
                                            ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                                            ->dehydrated(fn ($state) => filled($state))
                                            ->required(fn (string $operation): bool => $operation === 'create')
                                            ->minLength(8)
                                            ->revealable(),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Rôles et permissions')
                                    ->schema([
                                        Forms\Components\Select::make('roles')
                                            ->label('Rôles')
                                            ->relationship('roles', 'name')
                                            ->multiple()
                                            ->preload()
                                            ->searchable()
                                            ->live(),

                                        Forms\Components\Select::make('tenant_id')
                                            ->label('Tenant')
                                            ->relationship('tenant', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->nullable(),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Statut')
                                    ->schema([
                                        Forms\Components\DateTimePicker::make('email_verified_at')
                                            ->label('Email vérifié le')
                                            ->nullable(),

                                        Forms\Components\Toggle::make('marketplace_enabled')
                                            ->label('Marketplace activé')
                                            ->helperText('Activer les fonctionnalités marketplace pour cet utilisateur'),
                                    ])
                                    ->columns(2)
                                    ->collapsible(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Entreprise')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                Forms\Components\Section::make('Informations entreprise')
                                    ->schema([
                                        Forms\Components\TextInput::make('company_name')
                                            ->label('Nom de l\'entreprise')
                                            ->maxLength(255)
                                            ->placeholder('Durant Peinture SARL'),

                                        Forms\Components\TextInput::make('company_info.siret')
                                            ->label('SIRET')
                                            ->maxLength(14)
                                            ->placeholder('12345678901234'),

                                        Forms\Components\TextInput::make('company_info.phone')
                                            ->label('Téléphone')
                                            ->tel()
                                            ->placeholder('+33 1 23 45 67 89'),

                                        Forms\Components\TextInput::make('company_info.website')
                                            ->label('Site web')
                                            ->url()
                                            ->placeholder('https://durantpeinture.fr'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Adresse')
                                    ->schema([
                                        Forms\Components\TextInput::make('company_info.address')
                                            ->label('Adresse')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('company_info.postal_code')
                                            ->label('Code postal')
                                            ->maxLength(10),

                                        Forms\Components\TextInput::make('company_info.city')
                                            ->label('Ville')
                                            ->maxLength(100),
                                    ])
                                    ->columns(3)
                                    ->collapsible(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Branding')
                            ->icon('heroicon-o-paint-brush')
                            ->schema([
                                Forms\Components\Section::make('Personnalisation visuelle')
                                    ->description('Branding par défaut de cet utilisateur (peut être surchargé par les liens éditeurs)')
                                    ->schema([
                                        Forms\Components\TextInput::make('branding.welcome_message')
                                            ->label('Message de bienvenue')
                                            ->maxLength(255)
                                            ->placeholder('Bonjour, je suis l\'assistant de Durant Peinture...'),

                                        Forms\Components\ColorPicker::make('branding.primary_color')
                                            ->label('Couleur principale'),

                                        Forms\Components\TextInput::make('branding.logo_url')
                                            ->label('URL du logo')
                                            ->url()
                                            ->maxLength(500),

                                        Forms\Components\TextInput::make('branding.signature')
                                            ->label('Signature')
                                            ->maxLength(100)
                                            ->placeholder('Durant Peinture'),
                                    ])
                                    ->columns(2),
                            ]),

                        Forms\Components\Tabs\Tab::make('API & Quotas')
                            ->icon('heroicon-o-key')
                            ->schema([
                                Forms\Components\Section::make('Clé API')
                                    ->description('Pour les éditeurs et fabricants : accès API marketplace')
                                    ->schema([
                                        Forms\Components\Placeholder::make('api_key_display')
                                            ->label('Clé API actuelle')
                                            ->content(fn ($record) => $record?->api_key
                                                ? ($record->api_key_prefix . '_' . str_repeat('*', 10) . '...')
                                                : 'Aucune clé générée')
                                            ->visible(fn ($record) => $record !== null),

                                        Forms\Components\Placeholder::make('api_key_prefix_display')
                                            ->label('Préfixe')
                                            ->content(fn ($record) => $record?->api_key_prefix ?? '-')
                                            ->visible(fn ($record) => $record !== null),
                                    ])
                                    ->columns(2)
                                    ->visible(fn ($record) => $record !== null),

                                Forms\Components\Section::make('Quotas mensuels')
                                    ->schema([
                                        Forms\Components\TextInput::make('max_sessions_month')
                                            ->label('Max sessions/mois')
                                            ->numeric()
                                            ->placeholder('Illimité'),

                                        Forms\Components\TextInput::make('max_messages_month')
                                            ->label('Max messages/mois')
                                            ->numeric()
                                            ->placeholder('Illimité'),

                                        Forms\Components\TextInput::make('max_deployments')
                                            ->label('Max déploiements')
                                            ->numeric()
                                            ->placeholder('Illimité')
                                            ->helperText('Pour les éditeurs uniquement'),
                                    ])
                                    ->columns(3),

                                Forms\Components\Section::make('Compteurs actuels')
                                    ->schema([
                                        Forms\Components\Placeholder::make('current_sessions')
                                            ->label('Sessions ce mois')
                                            ->content(fn ($record) => $record?->current_month_sessions ?? 0),

                                        Forms\Components\Placeholder::make('current_messages')
                                            ->label('Messages ce mois')
                                            ->content(fn ($record) => $record?->current_month_messages ?? 0),
                                    ])
                                    ->columns(2)
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

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('company_name')
                    ->label('Entreprise')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Rôles')
                    ->badge()
                    ->separator(','),

                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('marketplace_enabled')
                    ->label('Marketplace')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Vérifié')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-circle')
                    ->getStateUsing(fn ($record) => $record->email_verified_at !== null),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),

                Tables\Filters\SelectFilter::make('roles')
                    ->label('Rôle')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('marketplace_enabled')
                    ->label('Marketplace'),

                Tables\Filters\Filter::make('verified')
                    ->label('Email vérifié')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('email_verified_at')),

                Tables\Filters\Filter::make('unverified')
                    ->label('Email non vérifié')
                    ->query(fn (Builder $query): Builder => $query->whereNull('email_verified_at')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('generateApiKey')
                    ->label('Générer API Key')
                    ->icon('heroicon-o-key')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Générer une nouvelle clé API ? L\'ancienne clé sera invalidée.')
                    ->visible(fn ($record) => $record->isEditeur() || $record->isFabricant())
                    ->action(function (User $record) {
                        $key = $record->generateApiKey();
                        Notification::make()
                            ->title('Clé API générée')
                            ->body("Nouvelle clé : {$key}")
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\Action::make('resetCounters')
                    ->label('RAZ compteurs')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->marketplace_enabled)
                    ->action(function (User $record) {
                        $record->resetMonthlyCounters();
                        Notification::make()
                            ->title('Compteurs réinitialisés')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
