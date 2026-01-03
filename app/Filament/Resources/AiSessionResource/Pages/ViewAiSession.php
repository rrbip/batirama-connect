<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiSessionResource\Pages;

use App\Events\Chat\AiMessageValidated;
use App\Filament\Resources\AiSessionResource;
use App\Models\AiMessage;
use App\Models\ConfigurableList;
use App\Models\SupportMessage;
use App\Services\AI\LearningService;
use App\Services\AI\MultiQuestionParser;
use App\Services\Support\EscalationService;
use App\Services\Support\SupportService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;
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
                        ->options(ConfigurableList::getOptionsForSelect(
                            ConfigurableList::KEY_RESOLUTION_TYPES,
                            ConfigurableList::getDefaultData(ConfigurableList::KEY_RESOLUTION_TYPES)
                        ))
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
                // Marquer le message support comme appris
                SupportMessage::where('id', $supportMessageId)->update([
                    'learned_at' => now(),
                    'learned_by' => auth()->id(),
                ]);

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
                    'learned_at' => $supportMsg->learned_at,
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

    // ─────────────────────────────────────────────────────────────────
    // APPRENTISSAGE MULTI-QUESTIONS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Valide un bloc spécifique d'une réponse multi-questions.
     */
    public function learnMultiQuestionBlock(
        int $messageId,
        int $blockId,
        string $question,
        string $answer,
        bool $requiresHandoff = false
    ): void {
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
            // Indexer la paire Q/R
            $result = app(LearningService::class)->indexLearnedResponse(
                question: trim($question),
                answer: trim($answer),
                agentId: $this->record->agent_id,
                agentSlug: $this->record->agent->slug,
                messageId: $messageId,
                validatorId: auth()->id(),
                requiresHandoff: $requiresHandoff
            );

            if ($result) {
                // Mettre à jour le statut du bloc dans rag_context
                $this->updateBlockLearnedStatus($message, $blockId);

                Notification::make()
                    ->title('Bloc appris')
                    ->body("Question {$blockId} indexée avec succès.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Erreur')
                    ->body('Impossible d\'indexer le bloc.')
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
     * Met à jour le statut d'apprentissage d'un bloc dans rag_context.
     */
    private function updateBlockLearnedStatus(AiMessage $message, int $blockId): void
    {
        $ragContext = $message->rag_context ?? [];

        if (!isset($ragContext['multi_question']['blocks'])) {
            return;
        }

        $parser = new MultiQuestionParser();
        $blocks = $parser->markBlockAsLearned(
            $ragContext['multi_question']['blocks'],
            $blockId,
            auth()->id()
        );

        $ragContext['multi_question']['blocks'] = $blocks;

        // Vérifier si tous les blocs sont appris
        if ($parser->allBlocksLearned($blocks)) {
            $message->update([
                'rag_context' => $ragContext,
                'validation_status' => 'learned',
                'validated_by' => auth()->id(),
                'validated_at' => now(),
            ]);
        } else {
            $message->update(['rag_context' => $ragContext]);
        }
    }

    /**
     * Récupère les statistiques d'apprentissage des blocs multi-questions.
     */
    public function getMultiQuestionStats(AiMessage $message): array
    {
        $ragContext = $message->rag_context ?? [];

        if (!isset($ragContext['multi_question']['blocks'])) {
            return ['learned' => 0, 'total' => 0, 'percentage' => 0];
        }

        $parser = new MultiQuestionParser();
        return $parser->getLearnedStats($ragContext['multi_question']['blocks']);
    }

    // ─────────────────────────────────────────────────────────────────
    // MODE APPRENTISSAGE ACCÉLÉRÉ
    // ─────────────────────────────────────────────────────────────────

    /**
     * Indique si la zone de réponse libre est déverrouillée.
     * En mode accéléré, elle est verrouillée par défaut.
     */
    public bool $canRespondFreely = false;

    /**
     * ID du message IA rejeté (pour indexer la réponse de l'agent).
     */
    public ?int $rejectedMessageId = null;

    /**
     * Vérifie si le mode apprentissage accéléré est actif.
     */
    public function isAcceleratedLearningMode(): bool
    {
        return $this->record->agent?->isAcceleratedLearningEnabled() ?? false;
    }

    /**
     * Rejette la réponse IA et déverrouille la zone de réponse.
     */
    public function rejectAndUnlock(int $messageId): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        try {
            // Marquer comme rejeté
            app(LearningService::class)->reject($message, auth()->id(), 'Agent a préféré rédiger');

            // Déverrouiller la zone de réponse
            $this->canRespondFreely = true;
            $this->rejectedMessageId = $messageId;

            Notification::make()
                ->title('Réponse rejetée')
                ->body('Vous pouvez maintenant rédiger votre réponse.')
                ->info()
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
     * Passe sans impact sur l'apprentissage.
     */
    public function skipToFreeResponse(?string $reason = null): void
    {
        // Vérifier si le skip est autorisé
        $agent = $this->record->agent;
        if ($agent && !$agent->allowsSkipInAcceleratedMode()) {
            Notification::make()
                ->title('Action non autorisée')
                ->body('Le bouton "Passer" est désactivé pour cet agent.')
                ->warning()
                ->send();
            return;
        }

        $this->canRespondFreely = true;
        $this->rejectedMessageId = null;

        // Logger le skip pour analyse
        Log::info('Agent skipped accelerated learning', [
            'session_id' => $this->record->id,
            'agent_id' => auth()->id(),
            'reason' => $reason,
        ]);

        Notification::make()
            ->title('Mode libre activé')
            ->body('Vous pouvez répondre librement.')
            ->info()
            ->send();
    }

    /**
     * Envoie la réponse libre ET l'indexe (après refus ou mode accéléré).
     */
    public function sendAndLearn(): void
    {
        if (empty(trim($this->supportMessage))) {
            Notification::make()
                ->title('Erreur')
                ->body('Le message ne peut pas être vide.')
                ->danger()
                ->send();
            return;
        }

        // Vérifier l'assignation
        if ($this->record->support_status === 'escalated') {
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
            // Envoyer le message
            app(SupportService::class)->sendAgentMessage(
                $this->record,
                auth()->user(),
                $this->supportMessage
            );

            // Récupérer la dernière question utilisateur pour l'apprentissage
            $lastUserMessage = $this->record->messages()
                ->where('role', 'user')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastUserMessage) {
                // Indexer la nouvelle paire Q/R
                app(LearningService::class)->indexLearnedResponse(
                    question: $lastUserMessage->content,
                    answer: $this->supportMessage,
                    agentId: $this->record->agent_id,
                    agentSlug: $this->record->agent->slug,
                    messageId: $lastUserMessage->id,
                    validatorId: auth()->id()
                );
            }

            $this->supportMessage = '';
            $this->canRespondFreely = false;
            $this->rejectedMessageId = null;

            Notification::make()
                ->title('Message envoyé et indexé')
                ->body('Votre réponse a été envoyée et l\'IA a appris de cette interaction.')
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
     * Retourne les raisons de skip disponibles.
     */
    public function getSkipReasons(): array
    {
        return $this->record->agent?->getSkipReasons() ?? [];
    }

    /**
     * Vérifie si un motif de skip est obligatoire.
     */
    public function requiresSkipReason(): bool
    {
        $config = $this->record->agent?->getAcceleratedLearningConfig() ?? [];
        return $config['require_skip_reason'] ?? false;
    }

    // ─────────────────────────────────────────────────────────────────
    // NOUVELLE UX : ENVOI UNIFIÉ DES BLOCS VALIDÉS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Envoie tous les blocs validés (mono ou multi-questions).
     *
     * Cette méthode unifie le comportement pour mono et multi-questions :
     * - Indexe chaque bloc Q/R dans Qdrant
     * - Met à jour le message avec corrected_content
     * - Broadcast au client si escaladé
     * - Envoie email si user_email présent
     *
     * @param int $messageId ID du message IA
     * @param array $blocks Liste des blocs à valider [['id' => 1, 'question' => '...', 'answer' => '...', 'requiresHandoff' => false], ...]
     */
    public function sendValidatedBlocks(int $messageId, array $blocks): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        if (empty($blocks)) {
            Notification::make()
                ->title('Erreur')
                ->body('Aucun bloc à valider.')
                ->danger()
                ->send();
            return;
        }

        try {
            $indexedCount = 0;
            $allAnswers = [];

            foreach ($blocks as $block) {
                $question = trim($block['question'] ?? '');
                $answer = trim($block['answer'] ?? '');
                $requiresHandoff = $block['requiresHandoff'] ?? false;
                $blockId = $block['id'] ?? 1;

                if (empty($question) || empty($answer)) {
                    continue;
                }

                // Indexer la paire Q/R dans Qdrant
                $result = app(LearningService::class)->indexLearnedResponse(
                    question: $question,
                    answer: $answer,
                    agentId: $this->record->agent_id,
                    agentSlug: $this->record->agent->slug,
                    messageId: $messageId,
                    validatorId: auth()->id(),
                    requiresHandoff: $requiresHandoff
                );

                if ($result) {
                    $indexedCount++;
                    $allAnswers[] = $answer;

                    // Mettre à jour le statut du bloc dans rag_context (si multi-questions)
                    if (count($blocks) > 1) {
                        $this->updateBlockLearnedStatus($message, $blockId);
                        $message->refresh();
                    }
                }
            }

            if ($indexedCount === 0) {
                Notification::make()
                    ->title('Erreur')
                    ->body('Aucun bloc n\'a pu être indexé.')
                    ->danger()
                    ->send();
                return;
            }

            // Construire le corrected_content (toutes les réponses concaténées pour multi, ou la réponse unique pour mono)
            $correctedContent = count($allAnswers) > 1
                ? implode("\n\n---\n\n", $allAnswers)
                : ($allAnswers[0] ?? $message->content);

            // Mettre à jour le message
            $message->update([
                'validation_status' => 'learned',
                'validated_by' => auth()->id(),
                'validated_at' => now(),
                'corrected_content' => $correctedContent,
            ]);

            // Broadcast au client si escaladé
            if ($this->record->isEscalated()) {
                broadcast(new AiMessageValidated($message));
            }

            // Envoyer email si user_email présent
            if ($this->record->user_email) {
                app(SupportService::class)->sendValidatedAiMessageByEmail(
                    $this->record,
                    $message,
                    auth()->user()
                );
            }

            $blockLabel = $indexedCount > 1 ? "{$indexedCount} blocs" : "1 bloc";
            Notification::make()
                ->title('Réponse envoyée')
                ->body($this->record->user_email
                    ? "{$blockLabel} indexé(s) et envoyé(s) par email au client."
                    : "{$blockLabel} indexé(s) et envoyé(s) au client.")
                ->success()
                ->send();

        } catch (\Throwable $e) {
            Log::error('sendValidatedBlocks error', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Rejette un bloc spécifique (utilisé dans la nouvelle UX).
     * En mono-question, cela équivaut à rejeter tout le message.
     * En multi-questions, seul le bloc concerné est marqué comme rejeté.
     *
     * @param int $messageId ID du message IA
     * @param int $blockId ID du bloc à rejeter (1 pour mono-question)
     * @param int $totalBlocks Nombre total de blocs
     */
    public function rejectBlock(int $messageId, int $blockId, int $totalBlocks = 1): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        try {
            // Si mono-question ou dernier bloc, rejeter tout le message
            if ($totalBlocks <= 1) {
                app(LearningService::class)->reject($message, auth()->id(), 'Bloc rejeté par agent');

                Notification::make()
                    ->title('Réponse rejetée')
                    ->body('La suggestion IA a été rejetée.')
                    ->info()
                    ->send();
            } else {
                // Multi-questions : marquer le bloc comme rejeté dans rag_context
                $ragContext = $message->rag_context ?? [];

                if (isset($ragContext['multi_question']['blocks'])) {
                    foreach ($ragContext['multi_question']['blocks'] as &$block) {
                        if (($block['id'] ?? 0) === $blockId) {
                            $block['rejected'] = true;
                            $block['rejected_at'] = now()->toIso8601String();
                            $block['rejected_by'] = auth()->id();
                            break;
                        }
                    }

                    $message->update(['rag_context' => $ragContext]);
                }

                Notification::make()
                    ->title('Bloc rejeté')
                    ->body("Question {$blockId} retirée de la réponse finale.")
                    ->info()
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
     * Rejette tous les blocs d'un message (équivalent à "Passer").
     * Utilisé quand l'agent décide de ne pas envoyer la réponse IA.
     *
     * @param int $messageId ID du message IA
     */
    public function rejectAllBlocks(int $messageId): void
    {
        $message = AiMessage::findOrFail($messageId);

        if ($message->session_id !== $this->record->id) {
            return;
        }

        try {
            app(LearningService::class)->reject($message, auth()->id(), 'Réponse IA passée par l\'agent');

            Notification::make()
                ->title('Réponse passée')
                ->body('La suggestion IA a été ignorée.')
                ->info()
                ->send();

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
