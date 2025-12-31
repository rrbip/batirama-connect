<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AiSessionResource\Pages;
use App\Models\AiSession;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AiSessionResource extends Resource
{
    protected static ?string $model = AiSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Sessions IA';

    protected static ?string $modelLabel = 'Session IA';

    protected static ?string $pluralModelLabel = 'Sessions IA';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('uuid')
                    ->label('ID')
                    ->limit(8)
                    ->copyable()
                    ->copyMessage('UUID copié')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->default('Visiteur')
                    ->searchable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->external_context['source'] ?? 'unknown')
                    ->color(fn ($state) => match($state) {
                        'admin_test' => 'warning',
                        'api' => 'info',
                        'public_link' => 'success',
                        'partner' => 'primary',
                        'whitelabel' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('deployment.name')
                    ->label('Déploiement')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('deployment.editor.name')
                    ->label('Éditeur')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('editorLink.artisan.name')
                    ->label('Artisan')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('message_count')
                    ->label('Messages')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('validation_summary')
                    ->label('Validation')
                    ->getStateUsing(function ($record) {
                        $stats = $record->messages()
                            ->where('role', 'assistant')
                            ->selectRaw("
                                SUM(CASE WHEN validation_status = 'pending' THEN 1 ELSE 0 END) as pending,
                                SUM(CASE WHEN validation_status = 'validated' THEN 1 ELSE 0 END) as validated,
                                SUM(CASE WHEN validation_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                                SUM(CASE WHEN validation_status = 'learned' THEN 1 ELSE 0 END) as learned
                            ")
                            ->first();

                        $parts = [];
                        if ($stats->pending > 0) $parts[] = "⏳{$stats->pending}";
                        if ($stats->validated > 0) $parts[] = "✅{$stats->validated}";
                        if ($stats->rejected > 0) $parts[] = "❌{$stats->rejected}";
                        if ($stats->learned > 0) $parts[] = "📚{$stats->learned}";

                        return implode(' ', $parts) ?: '-';
                    })
                    ->html(),

                Tables\Columns\TextColumn::make('support_status')
                    ->label('Support')
                    ->badge()
                    ->getStateUsing(fn ($record) => match ($record->support_status) {
                        'escalated' => '🔴 En attente',
                        'assigned' => '🟡 En cours',
                        'resolved' => '🟢 Résolu',
                        'abandoned' => '⚫ Abandonné',
                        default => null,
                    })
                    ->color(fn ($state) => match (true) {
                        str_contains($state ?? '', 'attente') => 'danger',
                        str_contains($state ?? '', 'cours') => 'warning',
                        str_contains($state ?? '', 'Résolu') => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'success' => 'active',
                        'gray' => 'archived',
                        'danger' => 'deleted',
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('agent')
                    ->relationship('agent', 'name')
                    ->label('Agent'),

                Tables\Filters\SelectFilter::make('deployment')
                    ->relationship('deployment', 'name')
                    ->label('Déploiement'),

                Tables\Filters\Filter::make('whitelabel_only')
                    ->label('Sessions whitelabel')
                    ->query(fn (Builder $query) => $query->whereNotNull('deployment_id'))
                    ->toggle(),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'archived' => 'Archivée',
                        'deleted' => 'Supprimée',
                    ])
                    ->label('Statut'),

                Tables\Filters\Filter::make('has_pending')
                    ->label('Avec messages à valider')
                    ->query(fn (Builder $query) => $query->whereHas('messages',
                        fn ($q) => $q->where('validation_status', 'pending')
                            ->where('role', 'assistant')
                    ))
                    ->toggle(),

                Tables\Filters\SelectFilter::make('validation_status')
                    ->label('Statut validation')
                    ->options([
                        'pending' => '⏳ En attente',
                        'validated' => '✅ Validées',
                        'rejected' => '❌ Rejetées',
                        'learned' => '📚 Corrigées (apprentissage)',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('messages', function ($q) use ($data) {
                                $q->where('role', 'assistant')
                                    ->where('validation_status', $data['value']);
                            });
                        }
                    }),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('Du'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),

                // Filtres support humain
                Tables\Filters\Filter::make('needs_support')
                    ->label('🔴 En attente de support')
                    ->query(fn (Builder $query) => $query->where('support_status', 'escalated'))
                    ->toggle(),

                Tables\Filters\Filter::make('in_support')
                    ->label('🟡 Support en cours')
                    ->query(fn (Builder $query) => $query->where('support_status', 'assigned'))
                    ->toggle(),

                Tables\Filters\SelectFilter::make('support_status')
                    ->label('Statut support')
                    ->options([
                        'escalated' => '🔴 En attente',
                        'assigned' => '🟡 En cours',
                        'resolved' => '🟢 Résolu',
                        'abandoned' => '⚫ Abandonné',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Voir')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => static::getUrl('view', ['record' => $record])),

                Tables\Actions\Action::make('archive')
                    ->label('Archiver')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'active')
                    ->action(fn ($record) => $record->update(['status' => 'archived'])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('archive')
                    ->label('Archiver')
                    ->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->update(['status' => 'archived'])),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiSessions::route('/'),
            'view' => Pages\ViewAiSession::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::whereHas('messages', function ($query) {
            $query->where('role', 'assistant')
                ->where('validation_status', 'pending');
        })->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
