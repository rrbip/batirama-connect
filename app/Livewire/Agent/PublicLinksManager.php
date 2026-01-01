<?php

declare(strict_types=1);

namespace App\Livewire\Agent;

use App\Models\Agent;
use App\Models\PublicAccessToken;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class PublicLinksManager extends Component implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    public Agent $agent;

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PublicAccessToken::query()->where('agent_id', $this->agent->id))
            ->columns([
                TextColumn::make('token')
                    ->label('Token')
                    ->limit(12)
                    ->copyable()
                    ->copyMessage('Token copié')
                    ->tooltip(fn ($record) => $record->token),

                TextColumn::make('url')
                    ->label('URL')
                    ->getStateUsing(fn ($record) => $record->getUrl())
                    ->limit(35)
                    ->copyable()
                    ->copyMessage('URL copiée')
                    ->tooltip(fn ($record) => $record->getUrl())
                    ->icon('heroicon-o-link'),

                TextColumn::make('status')
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

                TextColumn::make('uses_display')
                    ->label('Utilisations')
                    ->getStateUsing(fn ($record) => ($record->use_count ?? 0) . '/' . ($record->max_uses ?? '∞')),

                TextColumn::make('expires_at')
                    ->label('Expiration')
                    ->dateTime('d/m/Y H:i')
                    ->color(fn ($record) => $record->isExpired() ? 'danger' : null),

                TextColumn::make('client_email')
                    ->label('Email')
                    ->getStateUsing(fn ($record) => $record->client_info['email'] ?? null)
                    ->placeholder('—')
                    ->icon('heroicon-o-envelope')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('creator.name')
                    ->label('Créé par')
                    ->default('Système')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'used' => 'Utilisé',
                        'expired' => 'Expiré',
                        'revoked' => 'Révoqué',
                    ]),
            ])
            ->headerActions([
                Action::make('generate')
                    ->label('Générer un lien')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        TextInput::make('client_email')
                            ->label('Email client (optionnel)')
                            ->email()
                            ->placeholder('client@example.com')
                            ->helperText('Si renseigné, évite la collecte d\'email lors de l\'escalade'),

                        TextInput::make('max_uses')
                            ->label('Utilisations max')
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->helperText('1 = usage unique, vide = illimité'),

                        TextInput::make('expires_in_hours')
                            ->label('Expire dans (heures)')
                            ->numeric()
                            ->default($this->agent->default_token_expiry_hours ?? 168)
                            ->minValue(1)
                            ->helperText('168h = 7 jours, 720h = 30 jours'),

                        Textarea::make('note')
                            ->label('Note interne (optionnel)')
                            ->rows(2)
                            ->placeholder('Ex: Lien pour client X, devis #123...'),
                    ])
                    ->action(function (array $data) {
                        // Construire client_info avec email et note
                        $clientInfo = [];
                        if (!empty($data['client_email'])) {
                            $clientInfo['email'] = $data['client_email'];
                        }
                        if (!empty($data['note'])) {
                            $clientInfo['note'] = $data['note'];
                        }

                        $token = PublicAccessToken::create([
                            'token' => Str::random(32),
                            'agent_id' => $this->agent->id,
                            'created_by' => auth()->id(),
                            'expires_at' => now()->addHours((int) ($data['expires_in_hours'] ?? 168)),
                            'max_uses' => $data['max_uses'] ?? 1,
                            'use_count' => 0,
                            'status' => 'active',
                            'client_info' => !empty($clientInfo) ? $clientInfo : null,
                            'created_at' => now(),
                        ]);

                        $url = $token->getUrl();

                        Notification::make()
                            ->title('Lien généré avec succès')
                            ->body($url)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Action::make('copy_url')
                    ->label('')
                    ->icon('heroicon-o-clipboard')
                    ->color('gray')
                    ->tooltip('Copier l\'URL')
                    ->action(function ($record) {
                        // The copy is handled client-side via the copyable column
                        Notification::make()
                            ->title('URL')
                            ->body($record->getUrl())
                            ->info()
                            ->send();
                    }),

                Action::make('revoke')
                    ->label('')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->tooltip('Révoquer')
                    ->requiresConfirmation()
                    ->modalHeading('Révoquer ce lien ?')
                    ->modalDescription('Le lien ne sera plus utilisable.')
                    ->visible(fn ($record) => $record->status === 'active')
                    ->action(fn ($record) => $record->update(['status' => 'revoked'])),

                DeleteAction::make()
                    ->label('')
                    ->tooltip('Supprimer'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('revoke')
                        ->label('Révoquer')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['status' => 'revoked'])),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aucun lien public')
            ->emptyStateDescription('Générez un lien pour partager l\'accès à cet agent.')
            ->emptyStateIcon('heroicon-o-link')
            ->emptyStateActions([
                Action::make('generate_first')
                    ->label('Générer un premier lien')
                    ->icon('heroicon-o-plus')
                    ->action(function () {
                        $token = PublicAccessToken::create([
                            'token' => Str::random(32),
                            'agent_id' => $this->agent->id,
                            'created_by' => auth()->id(),
                            'expires_at' => now()->addHours($this->agent->default_token_expiry_hours ?? 168),
                            'max_uses' => 1,
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

    public function render(): View
    {
        return view('livewire.agent.public-links-manager');
    }
}
