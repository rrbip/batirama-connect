<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiMessage;
use App\Services\AI\RagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAiMessageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Nombre de tentatives avant échec définitif
     */
    public int $tries = 3;

    /**
     * Délai entre les tentatives (secondes)
     */
    public int $backoff = 30;

    /**
     * Timeout du job (5 minutes)
     */
    public int $timeout = 300;

    /**
     * Nombre maximum d'exceptions non gérées
     */
    public int $maxExceptions = 3;

    /**
     * Contenu du message utilisateur à traiter
     */
    private string $userContent;

    public function __construct(
        public AiMessage $message,
        string $userContent
    ) {
        $this->userContent = $userContent;
        $this->onQueue('ai-messages');
    }

    /**
     * Exécute le job
     */
    public function handle(RagService $ragService): void
    {
        $session = $this->message->session;
        $agent = $session->agent;

        // Marquer comme "processing"
        $this->message->markAsProcessing();

        Log::info('ProcessAiMessageJob: Starting', [
            'message_id' => $this->message->id,
            'message_uuid' => $this->message->uuid,
            'session_id' => $session->id,
            'agent' => $agent->slug,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Exécuter le RAG complet (embedding, recherche, LLM)
            $response = $ragService->query($agent, $this->userContent, $session);

            // Mettre à jour le message avec la réponse
            $this->message->markAsCompleted(
                content: $response->content,
                model: $response->model,
                tokensPrompt: $response->tokensPrompt,
                tokensCompletion: $response->tokensCompletion,
                generationTimeMs: $response->generationTimeMs,
                ragContext: $response->raw['context'] ?? null,
                usedFallback: $response->usedFallback
            );

            // Incrémenter le compteur de messages de la session
            $session->increment('message_count');

            Log::info('ProcessAiMessageJob: Completed successfully', [
                'message_id' => $this->message->id,
                'message_uuid' => $this->message->uuid,
                'model' => $response->model,
                'used_fallback' => $response->usedFallback,
                'generation_time_ms' => $response->generationTimeMs,
                'tokens_total' => ($response->tokensPrompt ?? 0) + ($response->tokensCompletion ?? 0),
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessAiMessageJob: Processing failed', [
                'message_id' => $this->message->id,
                'message_uuid' => $this->message->uuid,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);

            // Re-throw pour laisser Laravel gérer les retries
            throw $e;
        }
    }

    /**
     * Appelé quand toutes les tentatives ont échoué
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAiMessageJob: All attempts failed', [
            'message_id' => $this->message->id,
            'message_uuid' => $this->message->uuid,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->message->markAsFailed(
            error: $exception->getMessage(),
            attempt: $this->attempts()
        );
    }

    /**
     * ID unique pour éviter les doublons
     */
    public function uniqueId(): string
    {
        return 'ai-message-' . $this->message->id;
    }

    /**
     * Durée pendant laquelle le lock d'unicité est maintenu
     */
    public function uniqueFor(): int
    {
        return $this->timeout + 60; // timeout + marge
    }

    /**
     * Tags pour le monitoring (Laravel Horizon)
     */
    public function tags(): array
    {
        return [
            'ai-message',
            'message:' . $this->message->id,
            'session:' . $this->message->session_id,
            'agent:' . $this->message->session->agent->slug ?? 'unknown',
        ];
    }

    /**
     * Délai avant la première exécution (optionnel)
     */
    public function retryUntil(): \DateTime
    {
        // Le job peut être retenté pendant 10 minutes max
        return now()->addMinutes(10);
    }
}
