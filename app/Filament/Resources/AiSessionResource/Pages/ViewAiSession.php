<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiSessionResource\Pages;

use App\Events\Chat\AiMessageValidated;
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
    // WEBSOCKET LISTENERS
    // ─────────────────────────────────────────────────────────────────

    #[On('refreshMessages')]
    public function refreshMessages(): void
    {
        // Force refresh of the record and its messages
        $this->record->refresh();
        $this->record->load('messages', 'supportMessages');
    }

    #[On('refreshSession')]
    public function refreshSession(): void
    {
        $this->record->refresh();
    }

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

            // Broadcast au standalone si support humain actif
            // pour que l'utilisateur voie maintenant la réponse validée
            $message->refresh();
            if ($this->record->isEscalated()) {
                broadcast(new AiMessageValidated($message));
            }

            // Envoyer par email si le client a fourni son email (mode async)
            // Note: On envoie juste l'email, sans créer de SupportMessage car le message IA existe déjà
            if ($this->record->user_email) {
                app(SupportService::class)->sendValidatedAiMessageByEmail(
                    $this->record,
                    $message,
                    auth()->user()
                );
            }

            Notification::make()
                ->title('Réponse validée')
                ->body($this->record->user_email
                    ? 'La réponse a été validée et envoyée par email au client.'
                    : 'La réponse a été marquée comme correcte.')
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

    public function learnFromMessage(int $messageId, string $correctedContent, bool $requiresHandoff = false): void
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
                auth()->id(),
                $requiresHandoff
            );

            if ($result) {
                // Broadcast au standalone si support humain actif
                // pour que l'utilisateur voie la réponse corrigée
                $message->refresh();
                if ($this->record->isEscalated()) {
                    broadcast(new AiMessageValidated($message));
                }

                // Envoyer par email si le client a fourni son email (mode async)
                // Le mail utilisera corrected_content automatiquement
                if ($this->record->user_email) {
                    app(SupportService::class)->sendValidatedAiMessageByEmail(
                        $this->record,
                        $message,
                        auth()->user()
                    );
                }

                Notification::make()
                    ->title('Correction enregistrée')
                    ->body($this->record->user_email
                        ? 'La réponse corrigée a été indexée et envoyée par email au client.'
                        : 'La réponse corrigée a été indexée pour l\'apprentissage.')
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

    /**
     * Valide un message avec possibilité de modifier la question pour l'apprentissage.
     */
    public function validateMessageWithQuestion(int $messageId, string $question, bool $requiresHandoff = false): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        if (empty(trim($question))) {
            Notification::make()
                ->title('Erreur')
                ->body('La question ne peut pas être vide.')
                ->danger()
                ->send();
            return;
        }

        try {
            app(LearningService::class)->validate($message, auth()->id(), trim($question), $requiresHandoff);

            // Broadcast au standalone si support humain actif
            $message->refresh();
            if ($this->record->isEscalated()) {
                broadcast(new AiMessageValidated($message));
            }

            // Envoyer par email si le client a fourni son email (mode async)
            if ($this->record->user_email) {
                app(SupportService::class)->sendValidatedAiMessageByEmail(
                    $this->record,
                    $message,
                    auth()->user()
                );
            }

            Notification::make()
                ->title('Réponse validée')
                ->body($this->record->user_email
                    ? 'La réponse a été validée avec la question modifiée et envoyée par email.'
                    : 'La réponse a été validée avec la question modifiée.')
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
     * Apprend d'un message avec question et réponse modifiées.
     */
    public function learnFromMessageWithQuestion(int $messageId, string $question, string $answer, bool $requiresHandoff = false): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        if (empty(trim($question)) || empty(trim($answer))) {
            Notification::make()
                ->title('Erreur')
                ->body('La question et la réponse ne peuvent pas être vides.')
                ->danger()
                ->send();
            return;
        }

        try {
            $result = app(LearningService::class)->learnWithQuestion(
                $message,
                trim($question),
                trim($answer),
                auth()->id(),
                $requiresHandoff
            );

            if ($result) {
                // Broadcast au standalone si support humain actif
                $message->refresh();
                if ($this->record->isEscalated()) {
                    broadcast(new AiMessageValidated($message));
                }

                // Envoyer par email si le client a fourni son email (mode async)
                if ($this->record->user_email) {
                    app(SupportService::class)->sendValidatedAiMessageByEmail(
                        $this->record,
                        $message,
                        auth()->user()
                    );
                }

                Notification::make()
                    ->title('Correction enregistrée')
                    ->body($this->record->user_email
                        ? 'La question et réponse corrigées ont été indexées et envoyées par email.'
                        : 'La question et réponse corrigées ont été indexées pour l\'apprentissage.')
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

    /**
     * Apprend à l'IA avec question et réponse éditées par l'admin.
     */
    public function learnFromSupportMessageWithEdit(int $supportMessageId, string $question, string $answer): void
    {
        if (empty(trim($question)) || empty(trim($answer))) {
            Notification::make()
                ->title('Erreur')
                ->body('La question et la réponse sont obligatoires.')
                ->danger()
                ->send();
            return;
        }

        try {
            $learningService = app(\App\Services\AI\LearningService::class);

            $result = $learningService->indexLearnedResponse(
                question: trim($question),
                answer: trim($answer),
                agentId: $this->record->agent_id,
                agentSlug: $this->record->agent->slug,
                messageId: $supportMessageId,
                validatorId: auth()->id()
            );

            if ($result) {
                Notification::make()
                    ->title('Réponse apprise')
                    ->body("Q: " . \Illuminate\Support\Str::limit($question, 50) . "\nR: " . \Illuminate\Support\Str::limit($answer, 50))
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Erreur')
                    ->body('Impossible d\'indexer la réponse.')
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

    public function getChatMessages(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->record->messages()->orderBy('created_at', 'asc')->orderBy('id', 'asc')->get();
    }

    /**
     * Récupère tous les messages fusionnés (IA + Support) dans une timeline unifiée.
     *
     * @return array Messages triés par date avec type normalisé
     */
    public function getUnifiedMessages(): array
    {
        $messages = [];

        // Messages IA (user + assistant)
        foreach ($this->record->messages()->orderBy('created_at', 'asc')->orderBy('id', 'asc')->get() as $msg) {
            $messages[] = [
                'id' => 'ai_' . $msg->id,
                'original_id' => $msg->id,
                'source' => 'ai',
                'type' => $msg->role === 'user' ? 'client' : 'ai',
                'content' => $msg->content,
                'created_at' => $msg->created_at,
                'sender_name' => $msg->role === 'user' ? 'Client' : ($this->record->agent?->name ?? 'Assistant IA'),
                'validation_status' => $msg->validation_status,
                'model_used' => $msg->model_used,
                'tokens' => ($msg->tokens_prompt ?? 0) + ($msg->tokens_completion ?? 0),
                'generation_time_ms' => $msg->generation_time_ms,
                'rag_context' => $msg->rag_context,
                'corrected_content' => $msg->corrected_content,
                'original' => $msg,
                'is_pending_validation' => $msg->role === 'assistant' && $msg->validation_status === 'pending',
                'is_direct_match' => $msg->model_used === 'direct_qr_match',
            ];
        }

        // Messages de support (si escaladé)
        // - agent et system: toujours inclus
        // - user: seulement si channel='email' (les channel='chat' sont des doublons de ai_messages)
        if ($this->record->isEscalated()) {
            $supportMessagesQuery = $this->record->supportMessages()
                ->with('agent', 'attachments')
                ->where(function ($query) {
                    $query->whereIn('sender_type', ['agent', 'system'])
                          ->orWhere(function ($q) {
                              $q->where('sender_type', 'user')
                                ->where('channel', 'email');
                          });
                })
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc');

            foreach ($supportMessagesQuery->get() as $supportMsg) {
                // Déterminer le type
                $type = match ($supportMsg->sender_type) {
                    'agent' => 'support',
                    'system' => 'system',
                    'user' => 'client',
                    default => 'support',
                };

                $messages[] = [
                    'id' => 'support_' . $supportMsg->id,
                    'original_id' => $supportMsg->id,
                    'source' => 'support',
                    'type' => $type,
                    'content' => $supportMsg->content,
                    'created_at' => $supportMsg->created_at,
                    'sender_name' => $type === 'support'
                        ? ($supportMsg->agent?->name ?? 'Agent Support')
                        : ($type === 'system' ? 'Système' : 'Client'),
                    'channel' => $supportMsg->channel ?? 'chat',
                    'was_ai_improved' => $supportMsg->was_ai_improved ?? false,
                    'attachments' => $supportMsg->attachments ?? collect(),
                    'original' => $supportMsg,
                    'is_pending_validation' => false,
                ];
            }
        }

        // Trier par date puis par ID pour les messages simultanés
        usort($messages, function ($a, $b) {
            $dateCompare = $a['created_at'] <=> $b['created_at'];
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            // Si même date, trier par ID
            return $a['original_id'] <=> $b['original_id'];
        });

        // Dédupliquer les messages clients qui existent dans les deux tables (ai_messages + support_messages)
        // Quand un utilisateur envoie un message après escalade, il est enregistré dans les deux tables
        $seen = [];
        $deduplicated = [];
        foreach ($messages as $msg) {
            // Créer une clé unique basée sur le type, contenu et timestamp (arrondi à la seconde)
            $timestamp = $msg['created_at']?->format('Y-m-d H:i:s') ?? '';
            $key = $msg['type'] . '|' . md5($msg['content']) . '|' . $timestamp;

            // Si c'est un message client et qu'on l'a déjà vu, ignorer (préférer la version support)
            if ($msg['type'] === 'client' && isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $msg;
        }

        return $deduplicated;
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

    // ─────────────────────────────────────────────────────────────────
    // APPRENTISSAGE DEPUIS LE SUPPORT
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sauvegarde une Q/R depuis le chat de support.
     */
    public function saveAsLearnedResponse(int $messageId, string $question, string $answer): void
    {
        if (empty(trim($question)) || empty(trim($answer))) {
            Notification::make()
                ->title('Erreur')
                ->body('La question et la réponse ne peuvent pas être vides.')
                ->danger()
                ->send();
            return;
        }

        try {
            $learned = app(\App\Services\Support\SupportTrainingService::class)->saveQrPair(
                $this->record,
                $question,
                $answer,
                auth()->id(),
                $messageId
            );

            if ($learned) {
                Notification::make()
                    ->title('Q/R enregistrée')
                    ->body('La paire question/réponse a été indexée pour l\'apprentissage.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Erreur')
                    ->body('Impossible d\'enregistrer la Q/R.')
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

    /**
     * Indexe la conversation complète comme document.
     */
    public function indexConversationAsDocument(): void
    {
        try {
            \App\Jobs\Support\IndexConversationAsDocumentJob::dispatch($this->record);

            Notification::make()
                ->title('Indexation lancée')
                ->body('La conversation sera indexée dans quelques instants.')
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
     * Récupère les paires Q/R potentielles depuis le support.
     */
    public function getPendingQrPairs(): array
    {
        return app(\App\Services\Support\SupportTrainingService::class)
            ->getPendingQrPairs($this->record);
    }
}
