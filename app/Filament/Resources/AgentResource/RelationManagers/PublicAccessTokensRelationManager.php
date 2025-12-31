<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentResource\RelationManagers;

use App\Models\PublicAccessToken;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PublicAccessTokensRelationManager extends RelationManager
{
    protected static string $relationship = 'publicAccessTokens';

    protected static ?string $title = 'Liens Publics';

    protected static ?string $modelLabel = 'Lien public';

    protected static ?string $pluralModelLabel = 'Liens publics';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('token')
                    ->label('Token')
                    ->default(fn () => Str::random(32))
                    ->required()
                    ->readOnly()
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('max_uses')
                    ->label('Utilisations max')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->helperText('Nombre maximum d\'utilisations (1 = usage unique)'),

                Forms\Components\TextInput::make('expires_in_hours')
                    ->label('Expire dans (heures)')
                    ->numeric()
                    ->default(168)
                    ->minValue(1)
                    ->helperText('168h = 7 jours'),

                Forms\Components\Textarea::make('client_info.note')
                    ->label('Note interne')
                    ->rows(2)
                    ->placeholder('Ex: Lien pour client X, devis #123...')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('token')
            ->columns([
                Tables\Columns\TextColumn::make('token')
                    ->label('Token')
                    ->limit(12)
                    ->copyable()
                    ->copyMessage('Token copié')
                    ->tooltip(fn ($record) => $record->token),

                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->getStateUsing(fn ($record) => $record->getUrl())
                    ->limit(30)
                    ->copyable()
                    ->copyMessage('URL copiée')
                    ->tooltip(fn ($record) => $record->getUrl())
                    ->icon('heroicon-o-link'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'used' => 'gray',
                        'expired' => 'danger',
                        'revoked' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Actif',
                        'used' => 'Utilisé',
                        'expired' => 'Expiré',
                        'revoked' => 'Révoqué',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('uses_display')
                    ->label('Utilisations')
                    ->getStateUsing(fn ($record) => ($record->uses_count ?? $record->use_count ?? 0) . '/' . ($record->max_uses ?? '∞')),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expiration')
                    ->dateTime('d/m/Y H:i')
                    ->color(fn ($record) => $record->isExpired() ? 'danger' : null),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Créé par')
                    ->default('Système')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Dernière utilisation')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Jamais')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'used' => 'Utilisé',
                        'expired' => 'Expiré',
                        'revoked' => 'Révoqué',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('generate')
                    ->label('Générer un lien')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Forms\Components\TextInput::make('max_uses')
                            ->label('Utilisations max')
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->helperText('1 = usage unique, vide = illimité'),

                        Forms\Components\TextInput::make('expires_in_hours')
                            ->label('Expire dans (heures)')
                            ->numeric()
                            ->default(fn () => $this->getOwnerRecord()->default_token_expiry_hours ?? 168)
                            ->minValue(1)
                            ->helperText('168h = 7 jours, 720h = 30 jours'),

                        Forms\Components\Textarea::make('note')
                            ->label('Note interne (optionnel)')
                            ->rows(2)
                            ->placeholder('Ex: Lien pour client X, devis #123...'),
                    ])
                    ->action(function (array $data) {
                        $agent = $this->getOwnerRecord();

                        $token = PublicAccessToken::create([
                            'token' => Str::random(32),
                            'agent_id' => $agent->id,
                            'created_by' => auth()->id(),
                            'expires_at' => now()->addHours((int) ($data['expires_in_hours'] ?? 168)),
                            'max_uses' => $data['max_uses'] ?? 1,
                            'uses_count' => 0,
                            'use_count' => 0,
                            'status' => 'active',
                            'client_info' => $data['note'] ? ['note' => $data['note']] : null,
                            'created_at' => now(),
                        ]);

                        $url = $token->getUrl();

                        Notification::make()
                            ->title('Lien généré avec succès')
                            ->body("URL: {$url}")
                            ->success()
                            ->persistent()
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('copy')
                                    ->label('Copier l\'URL')
                                    ->url($url)
                                    ->openUrlInNewTab(),
                            ])
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('copy_url')
                    ->label('Copier')
                    ->icon('heroicon-o-clipboard')
                    ->color('gray')
                    ->action(function ($record) {
                        Notification::make()
                            ->title('URL copiée')
                            ->body($record->getUrl())
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('revoke')
                    ->label('Révoquer')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Révoquer ce lien ?')
                    ->modalDescription('Le lien ne sera plus utilisable.')
                    ->visible(fn ($record) => $record->status === 'active')
                    ->action(fn ($record) => $record->update(['status' => 'revoked'])),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('revoke')
                        ->label('Révoquer')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['status' => 'revoked'])),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aucun lien public')
            ->emptyStateDescription('Générez un lien pour partager l\'accès à cet agent.')
            ->emptyStateIcon('heroicon-o-link')
            ->emptyStateActions([
                Tables\Actions\Action::make('generate_first')
                    ->label('Générer un premier lien')
                    ->icon('heroicon-o-plus')
                    ->action(function () {
                        $agent = $this->getOwnerRecord();

                        $token = PublicAccessToken::create([
                            'token' => Str::random(32),
                            'agent_id' => $agent->id,
                            'created_by' => auth()->id(),
                            'expires_at' => now()->addHours($agent->default_token_expiry_hours ?? 168),
                            'max_uses' => 1,
                            'uses_count' => 0,
                            'use_count' => 0,
                            'status' => 'active',
                            'created_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Lien généré')
                            ->body($token->getUrl())
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ]);
    }
}
