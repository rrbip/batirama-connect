<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $modelLabel = 'Journal d\'audit';

    protected static ?string $pluralModelLabel = 'Journal d\'audit';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de l\'action')
                    ->schema([
                        Forms\Components\TextInput::make('action')
                            ->label('Action'),

                        Forms\Components\TextInput::make('user_email')
                            ->label('Utilisateur'),

                        Forms\Components\TextInput::make('ip_address')
                            ->label('Adresse IP'),

                        Forms\Components\DateTimePicker::make('created_at')
                            ->label('Date'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Cible')
                    ->schema([
                        Forms\Components\TextInput::make('auditable_type')
                            ->label('Type'),

                        Forms\Components\TextInput::make('auditable_id')
                            ->label('ID'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Anciennes valeurs')
                    ->schema([
                        Forms\Components\KeyValue::make('old_values')
                            ->label('')
                            ->disabled(),
                    ])
                    ->collapsed()
                    ->visible(fn ($record) => ! empty($record?->old_values)),

                Forms\Components\Section::make('Nouvelles valeurs')
                    ->schema([
                        Forms\Components\KeyValue::make('new_values')
                            ->label('')
                            ->disabled(),
                    ])
                    ->collapsed()
                    ->visible(fn ($record) => ! empty($record?->new_values)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'create' => 'Création',
                        'update' => 'Modification',
                        'delete' => 'Suppression',
                        'restore' => 'Restauration',
                        'login' => 'Connexion',
                        'logout' => 'Déconnexion',
                        'export' => 'Export',
                        default => ucfirst($state ?? '-'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'create' => 'success',
                        'update' => 'info',
                        'delete' => 'danger',
                        'restore' => 'warning',
                        'login' => 'primary',
                        'logout' => 'gray',
                        default => 'secondary',
                    }),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Type')
                    ->formatStateUsing(function (?string $state): string {
                        if (! $state) {
                            return '-';
                        }
                        $modelName = class_basename($state);

                        return match ($modelName) {
                            'User' => 'Utilisateur',
                            'Role' => 'Rôle',
                            'Permission' => 'Permission',
                            'Agent' => 'Agent IA',
                            'AiSession' => 'Session IA',
                            'Ouvrage' => 'Ouvrage',
                            'Document' => 'Document',
                            'Partner' => 'Partenaire',
                            default => $modelName,
                        };
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('ID')
                    ->default('-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user_email')
                    ->label('Utilisateur')
                    ->default('Système')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('Action')
                    ->options([
                        'create' => 'Création',
                        'update' => 'Modification',
                        'delete' => 'Suppression',
                        'restore' => 'Restauration',
                        'login' => 'Connexion',
                        'logout' => 'Déconnexion',
                    ]),

                Tables\Filters\SelectFilter::make('auditable_type')
                    ->label('Type')
                    ->options([
                        'App\\Models\\User' => 'Utilisateur',
                        'App\\Models\\Role' => 'Rôle',
                        'App\\Models\\Agent' => 'Agent IA',
                        'App\\Models\\AiSession' => 'Session IA',
                        'App\\Models\\Ouvrage' => 'Ouvrage',
                    ]),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('Du'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Au'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn ($query, $date) => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn ($query, $date) => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('60s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
