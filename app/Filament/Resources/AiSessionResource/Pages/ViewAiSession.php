<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiSessionResource\Pages;

use App\Filament\Resources\AiSessionResource;
use App\Models\AiMessage;
use App\Services\AI\LearningService;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAiSession extends ViewRecord
{
    protected static string $resource = AiSessionResource::class;

    protected static string $view = 'filament.resources.ai-session-resource.pages.view-ai-session';

    public function getTitle(): string
    {
        return "Session : " . substr($this->record->uuid, 0, 8) . '...';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('archive')
                ->label('Archiver')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'active')
                ->action(fn () => $this->record->update(['status' => 'archived'])),

            Actions\Action::make('back')
                ->label('Retour à la liste')
                ->icon('heroicon-o-arrow-left')
                ->url(AiSessionResource::getUrl('index'))
                ->color('gray'),
        ];
    }

    public function validateMessage(int $messageId): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        try {
            app(LearningService::class)->validate($message, auth()->id());

            Notification::make()
                ->title('Réponse validée')
                ->body('La réponse a été marquée comme correcte.')
                ->success()
                ->send();

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function rejectMessage(int $messageId, ?string $reason = null): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        try {
            app(LearningService::class)->reject($message, auth()->id(), $reason);

            Notification::make()
                ->title('Réponse rejetée')
                ->body('La réponse a été marquée comme incorrecte.')
                ->success()
                ->send();

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function learnFromMessage(int $messageId, string $correctedContent): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        if (empty(trim($correctedContent))) {
            Notification::make()
                ->title('Erreur')
                ->body('Le contenu corrigé ne peut pas être vide.')
                ->danger()
                ->send();
            return;
        }

        try {
            $result = app(LearningService::class)->learn(
                $message,
                $correctedContent,
                auth()->id()
            );

            if ($result) {
                Notification::make()
                    ->title('Correction enregistrée')
                    ->body('La réponse corrigée a été indexée pour l\'apprentissage.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Erreur')
                    ->body('Impossible d\'indexer la correction.')
                    ->danger()
                    ->send();
            }

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getMessages(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->record->messages()->orderBy('created_at', 'asc')->orderBy('id', 'asc')->get();
    }

    public function getSessionStats(): array
    {
        $messages = $this->record->messages;

        return [
            'total_messages' => $messages->count(),
            'user_messages' => $messages->where('role', 'user')->count(),
            'assistant_messages' => $messages->where('role', 'assistant')->count(),
            'pending_validation' => $messages->where('role', 'assistant')->where('validation_status', 'pending')->count(),
            'validated' => $messages->where('role', 'assistant')->where('validation_status', 'validated')->count(),
            'learned' => $messages->where('role', 'assistant')->where('validation_status', 'learned')->count(),
            'rejected' => $messages->where('role', 'assistant')->where('validation_status', 'rejected')->count(),
            'total_tokens' => $messages->where('role', 'assistant')->sum('tokens_prompt') + $messages->where('role', 'assistant')->sum('tokens_completion'),
            'avg_generation_time' => round($messages->where('role', 'assistant')->avg('generation_time_ms') ?? 0),
        ];
    }
}
