<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiSessionResource\Pages;

use App\Filament\Resources\AiSessionResource;
use App\Models\AiMessage;
use App\Models\SupportMessage;
use App\Services\AI\LearningService;
use App\Services\Support\EscalationService;
use App\Services\Support\SupportService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewAiSession extends ViewRecord
{
    protected static string $resource = AiSessionResource::class;

    protected static string $view = 'filament.resources.ai-session-resource.pages.view-ai-session';

    // ─────────────────────────────────────────────────────────────────
    // PROPRIÉTÉS SUPPORT HUMAIN
    // ─────────────────────────────────────────────────────────────────

    public string $supportMessage = '';

    public function getTitle(): string
    {
        $title = "Session : " . substr($this->record->uuid, 0, 8) . '...';

        if ($this->record->isEscalated()) {
            $statusLabel = match ($this->record->support_status) {
                'escalated' => '🔴 En attente',
                'assigned' => '🟡 En cours',
                'resolved' => '🟢 Résolu',
                'abandoned' => '⚫ Abandonné',
                default => '',
            };
            $title .= " [{$statusLabel}]";
        }

        return $title;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Action : Prendre en charge
            Actions\Action::make('takeOver')
                ->label('Prendre en charge')
                ->icon('heroicon-o-hand-raised')
                ->color('success')
                ->visible(fn () => $this->record->support_status === 'escalated')
                ->requiresConfirmation()
                ->modalHeading('Prendre en charge cette demande ?')
                ->modalDescription('Vous allez être assigné comme agent de support pour cette conversation.')
                ->action(fn () => $this->takeOverSession()),

            // Action : Résoudre
            Actions\Action::make('resolve')
                ->label('Résoudre')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->support_status === 'assigned'
                    && $this->record->support_agent_id === auth()->id())
                ->form([
                    Select::make('resolution_type')
                        ->label('Type de résolution')
                        ->options([
                            'answered' => 'Question répondue',
                            'redirected' => 'Redirigé vers autre service',
                            'out_of_scope' => 'Hors périmètre',
                            'duplicate' => 'Question déjà traitée',
                        ])
                        ->required(),
                    Textarea::make('notes')
                        ->label('Notes (optionnel)')
                        ->rows(3),
                ])
                ->action(fn (array $data) => $this->resolveSession($data['resolution_type'], $data['notes'] ?? null)),

            // Action : Archiver
            Actions\Action::make('archive')
                ->label('Archiver')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'active' && !$this->record->isEscalated())
                ->action(fn () => $this->record->update(['status' => 'archived'])),

            Actions\Action::make('back')
                ->label('Retour à la liste')
                ->icon('heroicon-o-arrow-left')
                ->url(AiSessionResource::getUrl('index'))
                ->color('gray'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // ACTIONS SUPPORT HUMAIN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Prend en charge la session de support.
     */
    public function takeOverSession(): void
    {
        try {
            app(SupportService::class)->takeOverSession($this->record, auth()->user());

            Notification::make()
                ->title('Session prise en charge')
                ->body('Vous êtes maintenant assigné à cette conversation.')
                ->success()
                ->send();

            $this->redirect(request()->header('Referer'));

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Envoie un message de support.
     */
    public function sendSupportMessage(): void
    {
        if (empty(trim($this->supportMessage))) {
            Notification::make()
                ->title('Erreur')
                ->body('Le message ne peut pas être vide.')
                ->danger()
                ->send();
            return;
        }

        // Vérifier que l'utilisateur est bien assigné ou peut prendre en charge
        if ($this->record->support_status === 'escalated') {
            // Auto-assignation si pas encore assigné
            app(SupportService::class)->takeOverSession($this->record, auth()->user());
            $this->record->refresh();
        } elseif ($this->record->support_agent_id !== auth()->id()) {
            Notification::make()
                ->title('Erreur')
                ->body('Vous n\'êtes pas assigné à cette conversation.')
                ->danger()
                ->send();
            return;
        }

        try {
            app(SupportService::class)->sendAgentMessage(
                $this->record,
                auth()->user(),
                $this->supportMessage
            );

            $this->supportMessage = '';

            Notification::make()
                ->title('Message envoyé')
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

    /**
     * Résout la session de support.
     */
    public function resolveSession(string $resolutionType, ?string $notes = null): void
    {
        try {
            app(SupportService::class)->resolveSession(
                $this->record,
                auth()->user(),
                $resolutionType,
                $notes
            );

            Notification::make()
                ->title('Session résolue')
                ->body('La conversation a été marquée comme résolue.')
                ->success()
                ->send();

            $this->redirect(request()->header('Referer'));

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // GETTERS SUPPORT HUMAIN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Récupère les messages de support.
     */
    public function getSupportMessages(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->record->supportMessages()
            ->with('agent', 'attachments')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Vérifie si l'utilisateur actuel peut gérer le support.
     */
    public function canHandleSupport(): bool
    {
        $user = auth()->user();

        // Super-admin et admin peuvent toujours
        if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
            return true;
        }

        // Vérifie si assigné spécifiquement à cet agent
        return $this->record->agent?->userCanHandleSupport($user) ?? false;
    }

    /**
     * Vérifie si l'utilisateur est l'agent assigné.
     */
    public function isAssignedAgent(): bool
    {
        return $this->record->support_agent_id === auth()->id();
    }

    // ─────────────────────────────────────────────────────────────────
    // ASSISTANCE IA
    // ─────────────────────────────────────────────────────────────────

    public ?string $suggestedResponse = null;
    public array $ragSources = [];

    /**
     * Suggère une réponse basée sur le RAG.
     */
    public function suggestAiResponse(): void
    {
        // Récupérer le dernier message utilisateur
        $lastUserMessage = $this->record->messages()
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastUserMessage) {
            Notification::make()
                ->title('Pas de message')
                ->body('Aucun message utilisateur trouvé.')
                ->warning()
                ->send();
            return;
        }

        try {
            $result = app(\App\Services\Support\AgentAssistanceService::class)
                ->suggestResponse($this->record, $lastUserMessage->content);

            if (!$result || !$result['has_sources']) {
                Notification::make()
                    ->title('Pas de suggestion')
                    ->body('Aucune source pertinente trouvée dans la base de connaissances.')
                    ->warning()
                    ->send();
                return;
            }

            $this->suggestedResponse = $result['suggested_response'];
            $this->ragSources = $result['sources'];

            Notification::make()
                ->title('Suggestion générée')
                ->body('Une réponse a été suggérée basée sur ' . count($result['sources']) . ' source(s).')
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

    /**
     * Améliore le message actuel avec l'IA.
     */
    public function improveWithAi(): void
    {
        if (empty(trim($this->supportMessage))) {
            Notification::make()
                ->title('Message vide')
                ->body('Écrivez d\'abord un message à améliorer.')
                ->warning()
                ->send();
            return;
        }

        try {
            $improved = app(\App\Services\Support\AgentAssistanceService::class)
                ->improveResponse($this->record, $this->supportMessage);

            if (!$improved) {
                Notification::make()
                    ->title('Pas d\'amélioration')
                    ->body('Le message est déjà optimal ou l\'IA n\'a pas pu l\'améliorer.')
                    ->info()
                    ->send();
                return;
            }

            // Garder l'original et mettre à jour
            $this->supportMessage = $improved;

            Notification::make()
                ->title('Message amélioré')
                ->body('Votre message a été reformulé par l\'IA.')
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

    /**
     * Utilise la suggestion comme message.
     */
    public function useSuggestion(): void
    {
        if ($this->suggestedResponse) {
            $this->supportMessage = $this->suggestedResponse;
            $this->suggestedResponse = null;
            $this->ragSources = [];

            Notification::make()
                ->title('Suggestion appliquée')
                ->success()
                ->send();
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // VALIDATION / APPRENTISSAGE
    // ─────────────────────────────────────────────────────────────────

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
