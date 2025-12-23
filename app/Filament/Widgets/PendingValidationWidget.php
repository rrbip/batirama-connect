<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\AiSessionResource;
use App\Models\AiMessage;
use App\Models\AiSession;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingValidationWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Réponses à valider';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AiMessage::query()
                    ->where('role', 'assistant')
                    ->where('validation_status', 'pending')
                    ->with(['session.agent', 'session.user'])
                    ->latest('created_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('session.agent.name')
                    ->label('Agent')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('question')
                    ->label('Question')
                    ->getStateUsing(function ($record) {
                        $question = $record->session->messages()
                            ->where('role', 'user')
                            ->where('created_at', '<', $record->created_at)
                            ->orderBy('created_at', 'desc')
                            ->first();
                        return $question ? \Illuminate\Support\Str::limit($question->content, 60) : '-';
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('content')
                    ->label('Réponse')
                    ->limit(80)
                    ->wrap(),

                Tables\Columns\TextColumn::make('session.user.name')
                    ->label('Utilisateur')
                    ->default('Visiteur'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Voir')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => AiSessionResource::getUrl('view', ['record' => $record->session_id])),
            ])
            ->paginated([5])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aucune réponse en attente')
            ->emptyStateDescription('Toutes les réponses ont été validées.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public static function canView(): bool
    {
        return AiMessage::where('role', 'assistant')
            ->where('validation_status', 'pending')
            ->exists();
    }
}
