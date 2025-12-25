<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use App\Jobs\ProcessAiMessageJob;
use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Services\AI\DispatcherService;
use App\Services\AI\OllamaService;
use Filament\Actions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;

class TestAgent extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = AgentResource::class;

    protected static string $view = 'filament.resources.agent-resource.pages.test-agent';

    public ?int $testSessionId = null;

    public array $messages = [];

    public ?string $userMessage = '';

    public bool $isLoading = false;

    // ID du message en cours de traitement (pour polling)
    public ?string $pendingMessageUuid = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Restaurer la dernière session de test pour cet agent
        $this->restoreLastSession();
    }

    /**
     * Restaure la dernière session de test pour cet agent
     */
    protected function restoreLastSession(): void
    {
        $cacheKey = $this->getSessionCacheKey();
        $savedSessionId = Cache::get($cacheKey);

        if ($savedSessionId) {
            $session = AiSession::with('messages')->find($savedSessionId);

            if ($session && $session->agent_id === $this->getRecord()->id) {
                $this->testSessionId = $session->id;
                $this->loadMessagesFromSession($session);

                // Vérifier si un message est en cours de traitement
                $pendingMessage = $session->messages()
                    ->where('role', 'assistant')
                    ->whereIn('processing_status', [
                        AiMessage::STATUS_PENDING,
                        AiMessage::STATUS_QUEUED,
                        AiMessage::STATUS_PROCESSING,
                    ])
                    ->first();

                if ($pendingMessage) {
                    $this->pendingMessageUuid = $pendingMessage->uuid;
                    $this->isLoading = true;
                }
            }
        }
    }

    /**
     * Charge les messages depuis une session existante
     */
    protected function loadMessagesFromSession(AiSession $session): void
    {
        $this->messages = $session->messages()
            ->orderBy('created_at')
            ->orderBy('id') // Secondaire pour garantir l'ordre
            ->get()
            ->map(function ($msg) {
                $data = [
                    'role' => $msg->role,
                    'content' => $msg->content ?: '',
                    'timestamp' => $msg->created_at->format('H:i:s'),
                    'uuid' => $msg->uuid,
                    'id' => $msg->id, // Garder l'ID pour le tri
                ];

                // Pour les messages assistant
                if ($msg->role === 'assistant') {
                    $data['processing_status'] = $msg->processing_status;
                    $data['tokens'] = $msg->tokens_prompt || $msg->tokens_completion
                        ? ($msg->tokens_prompt ?? 0) + ($msg->tokens_completion ?? 0)
                        : null;
                    $data['generation_time_ms'] = $msg->generation_time_ms;
                    $data['processing_error'] = $msg->processing_error;
                    $data['model_used'] = $msg->model_used;
                    $data['used_fallback_model'] = $msg->used_fallback_model;

                    // Contexte RAG complet pour le bouton "Voir le contexte envoyé à l'IA"
                    if ($msg->rag_context) {
                        $data['rag_context'] = $msg->rag_context;

                        // Sources extraites pour affichage rapide
                        if (isset($msg->rag_context['sources'])) {
                            $data['sources'] = $msg->rag_context['sources'];
                        }
                    }
                }

                return $data;
            })
            ->toArray();
    }

    /**
     * Clé de cache pour stocker l'ID de session
     */
    protected function getSessionCacheKey(): string
    {
        return 'agent_test_session_' . auth()->id() . '_' . $this->getRecord()->id;
    }

    /**
     * Sauvegarde l'ID de session dans le cache
     */
    protected function saveSessionToCache(): void
    {
        Cache::put(
            $this->getSessionCacheKey(),
            $this->testSessionId,
            now()->addDays(7)
        );
    }

    public function getTitle(): string
    {
        return "Tester : {$this->getRecord()->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit')
                ->label('Modifier')
                ->icon('heroicon-o-pencil')
                ->url(fn () => AgentResource::getUrl('edit', ['record' => $this->record])),

            Actions\Action::make('newSession')
                ->label('Nouvelle session')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    Cache::forget($this->getSessionCacheKey());

                    $this->testSessionId = null;
                    $this->messages = [];
                    $this->pendingMessageUuid = null;
                    $this->isLoading = false;

                    Notification::make()
                        ->title('Session réinitialisée')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('viewSession')
                ->label('Voir la session')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn () => $this->testSessionId !== null)
                ->url(fn () => route('filament.admin.resources.ai-sessions.view', ['record' => $this->testSessionId])),
        ];
    }

    /**
     * Envoie un message de manière asynchrone
     */
    public function sendMessage(string $message = ''): void
    {
        $message = trim($message) ?: trim($this->userMessage);

        if (empty($message)) {
            return;
        }

        $this->isLoading = true;
        $this->userMessage = '';

        // Note: Le message utilisateur est affiché via l'UI optimiste (pendingMessage)
        // Il sera chargé depuis la DB quand le traitement sera terminé

        try {
            // Vérifier la disponibilité d'Ollama
            $ollama = OllamaService::forAgent($this->getRecord());
            if (!$ollama->isAvailable()) {
                throw new \RuntimeException(
                    "Le serveur Ollama n'est pas accessible ({$ollama->getBaseUrl()})."
                );
            }

            /** @var DispatcherService $dispatcher */
            $dispatcher = app(DispatcherService::class);

            // Créer une session si nécessaire
            if (!$this->testSessionId) {
                $session = $dispatcher->createSession($this->getRecord(), auth()->user(), 'admin_test');
                $this->testSessionId = $session->id;
                $this->saveSessionToCache();
            }

            $session = AiSession::find($this->testSessionId);

            // Dispatcher de manière asynchrone
            $assistantMessage = $dispatcher->dispatchAsync(
                $message,
                $this->getRecord(),
                auth()->user(),
                $session,
                'admin_test'
            );

            // Stocker l'UUID pour le polling
            $this->pendingMessageUuid = $assistantMessage->uuid;

            // Note: Les messages seront rechargés depuis la DB quand le traitement sera terminé
            // L'UI affiche l'indicateur de traitement via isProcessing

        } catch (\Throwable $e) {
            $this->messages[] = [
                'role' => 'error',
                'content' => "Erreur: {$e->getMessage()}",
                'timestamp' => now()->format('H:i'),
            ];

            $this->isLoading = false;
            $this->pendingMessageUuid = null;

            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->js('window.dispatchEvent(new CustomEvent("message-sent"))');
    }

    /**
     * Polling : vérifie le statut du message en cours
     */
    public function checkMessageStatus(): array
    {
        if (!$this->pendingMessageUuid) {
            return ['done' => true, 'status' => null];
        }

        $message = AiMessage::where('uuid', $this->pendingMessageUuid)->first();

        if (!$message) {
            $this->pendingMessageUuid = null;
            $this->isLoading = false;
            return ['done' => true, 'error' => 'Message non trouvé'];
        }

        $status = $message->processing_status;

        // Mettre à jour le message dans la liste
        $this->updateMessageInList($message);

        if ($status === AiMessage::STATUS_COMPLETED) {
            $this->pendingMessageUuid = null;
            $this->isLoading = false;

            // Recharger tous les messages depuis la DB (incluant le message utilisateur)
            if ($this->testSessionId) {
                $session = AiSession::find($this->testSessionId);
                if ($session) {
                    $this->loadMessagesFromSession($session);
                }
            }

            $this->js('window.dispatchEvent(new CustomEvent("message-received"))');

            return [
                'done' => true,
                'status' => $status,
                'content' => $message->content,
            ];
        }

        if ($status === AiMessage::STATUS_FAILED) {
            $this->pendingMessageUuid = null;
            $this->isLoading = false;

            // Recharger tous les messages depuis la DB
            if ($this->testSessionId) {
                $session = AiSession::find($this->testSessionId);
                if ($session) {
                    $this->loadMessagesFromSession($session);
                }
            }

            $this->js('window.dispatchEvent(new CustomEvent("message-received"))');

            return [
                'done' => true,
                'status' => $status,
                'error' => $message->processing_error,
            ];
        }

        // Encore en cours
        return [
            'done' => false,
            'status' => $status,
            'queue_position' => $this->getQueuePosition($message),
        ];
    }

    /**
     * Met à jour le message dans la liste des messages
     */
    protected function updateMessageInList(AiMessage $message): void
    {
        foreach ($this->messages as $index => $msg) {
            if (isset($msg['uuid']) && $msg['uuid'] === $message->uuid) {
                $data = [
                    'role' => 'assistant',
                    'content' => $message->content ?: '',
                    'timestamp' => $msg['timestamp'],
                    'uuid' => $message->uuid,
                    'processing_status' => $message->processing_status,
                    'processing_error' => $message->processing_error,
                    'tokens' => $message->tokens_prompt || $message->tokens_completion
                        ? ($message->tokens_prompt ?? 0) + ($message->tokens_completion ?? 0)
                        : null,
                    'generation_time_ms' => $message->generation_time_ms,
                    'model_used' => $message->model_used,
                    'used_fallback_model' => $message->used_fallback_model,
                ];

                // Contexte RAG complet pour le bouton "Voir le contexte envoyé à l'IA"
                if ($message->rag_context) {
                    $data['rag_context'] = $message->rag_context;

                    if (isset($message->rag_context['sources'])) {
                        $data['sources'] = $message->rag_context['sources'];
                    }
                }

                $this->messages[$index] = $data;
                break;
            }
        }
    }

    /**
     * Met à jour le contexte RAG dans le message utilisateur précédent
     */
    protected function updateUserMessageContext(AiMessage $assistantMessage): void
    {
        if (!$assistantMessage->rag_context) {
            return;
        }

        // Trouver le dernier message utilisateur avant ce message assistant
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['role'] === 'user') {
                $this->messages[$i]['rag_context'] = $assistantMessage->rag_context;
                break;
            }
        }
    }

    /**
     * Calcule la position dans la queue
     */
    protected function getQueuePosition(AiMessage $message): int
    {
        return AiMessage::where('role', 'assistant')
            ->whereIn('processing_status', [AiMessage::STATUS_PENDING, AiMessage::STATUS_QUEUED])
            ->where(function ($query) use ($message) {
                $query->where('queued_at', '<', $message->queued_at ?? $message->created_at)
                    ->orWhere(function ($q) use ($message) {
                        $q->where('queued_at', '=', $message->queued_at ?? $message->created_at)
                          ->where('id', '<', $message->id);
                    });
            })
            ->count() + 1;
    }

    /**
     * Relance un message en échec
     */
    public function retryMessage(string $uuid): void
    {
        $message = AiMessage::where('uuid', $uuid)
            ->where('processing_status', AiMessage::STATUS_FAILED)
            ->first();

        if (!$message) {
            Notification::make()
                ->title('Message non trouvé')
                ->danger()
                ->send();
            return;
        }

        // Trouver le message utilisateur correspondant
        $userMessage = AiMessage::where('session_id', $message->session_id)
            ->where('role', 'user')
            ->where('created_at', '<', $message->created_at)
            ->orderByDesc('created_at')
            ->first();

        if (!$userMessage) {
            Notification::make()
                ->title('Message utilisateur non trouvé')
                ->danger()
                ->send();
            return;
        }

        // Réinitialiser et relancer
        $message->resetForRetry();
        $message->increment('retry_count');

        dispatch(new ProcessAiMessageJob($message, $userMessage->content));
        $message->markAsQueued();

        // Mettre à jour l'UI
        $this->pendingMessageUuid = $message->uuid;
        $this->isLoading = true;
        $this->updateMessageInList($message);

        Notification::make()
            ->title('Message relancé')
            ->success()
            ->send();
    }

    #[Computed]
    public function agentInfo(): array
    {
        $record = $this->getRecord();

        return [
            'Modèle' => $record->model ?? 'Par défaut',
            'Température' => $record->temperature ?? 0.7,
            'Max tokens' => $record->max_tokens ?? 2048,
            'Mode RAG' => $record->retrieval_mode ?? 'VECTOR_ONLY',
            'Collection' => $record->qdrant_collection ?? '-',
        ];
    }

    #[Computed]
    public function ollamaStatus(): array
    {
        $ollama = OllamaService::forAgent($this->getRecord());
        $available = $ollama->isAvailable();

        return [
            'available' => $available,
            'url' => $ollama->getBaseUrl(),
            'models' => $available ? $ollama->listModels() : [],
        ];
    }

    public function getTestSession(): ?AiSession
    {
        return $this->testSessionId ? AiSession::find($this->testSessionId) : null;
    }
}
