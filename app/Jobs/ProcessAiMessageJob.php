<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\Chat\AiMessageCompleted;
use App\Events\Chat\AiMessageFailed;
use App\Models\AiMessage;
use App\Services\AI\RagService;
use App\Services\Support\EscalationService;
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

            // Vérifier si l'IA demande un handoff humain (via marqueur)
            $needsHandoff = $this->checkHandoffMarker($response->content);
            $cleanContent = $this->removeHandoffMarker($response->content);

            // Backup: Vérifier si l'utilisateur demande explicitement un humain
            $userRequestsHuman = $this->checkUserRequestsHuman($this->userContent);

            // Mettre à jour le message avec la réponse (sans le marqueur)
            $this->message->markAsCompleted(
                content: $cleanContent,
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
                'needs_handoff' => $needsHandoff,
            ]);

            // Broadcast completion event via WebSocket AVANT l'escalade
            // Cela garantit que le message IA est affiché avant le message d'escalade
            $session = $this->message->session;
            Log::info('Broadcasting AiMessageCompleted', [
                'message_id' => $this->message->uuid,
                'session_id' => $session->uuid,
                'channels' => ['chat.message.' . $this->message->uuid, 'chat.session.' . $session->uuid],
            ]);
            broadcast(new AiMessageCompleted($this->message));

            // Déclencher l'escalade APRÈS le broadcast du message IA
            // 1. L'IA a ajouté le marqueur [HANDOFF_NEEDED]
            // 2. OU l'utilisateur a explicitement demandé un humain
            // 3. OU le score RAG est inférieur au seuil d'escalade
            if ($agent->human_support_enabled) {
                $maxRagScore = $this->extractMaxRagScore($response->raw);
                $escalationThreshold = $agent->escalation_threshold ?? 0.60;
                $scoreBelowThreshold = $maxRagScore < $escalationThreshold;

                if ($needsHandoff || $userRequestsHuman || $scoreBelowThreshold) {
                    $reason = match (true) {
                        $userRequestsHuman => 'user_explicit_request',
                        $needsHandoff => 'ai_handoff_request',
                        $scoreBelowThreshold => 'low_rag_score',
                        default => 'unknown',
                    };
                    $this->triggerEscalation($session, $reason, $maxRagScore);

                    Log::info('ProcessAiMessageJob: Handoff triggered', [
                        'session_id' => $session->id,
                        'reason' => $reason,
                        'ai_marker' => $needsHandoff,
                        'user_request' => $userRequestsHuman,
                        'max_rag_score' => $maxRagScore,
                        'escalation_threshold' => $escalationThreshold,
                        'score_below_threshold' => $scoreBelowThreshold,
                    ]);
                }
            }

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

        // Broadcast failure event via WebSocket
        broadcast(new AiMessageFailed($this->message));
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

    /**
     * Vérifie si la réponse contient le marqueur de handoff
     */
    private function checkHandoffMarker(string $content): bool
    {
        return str_contains($content, '[HANDOFF_NEEDED]');
    }

    /**
     * Supprime le marqueur de handoff du contenu
     */
    private function removeHandoffMarker(string $content): string
    {
        // Supprimer le marqueur et les lignes vides autour
        $content = preg_replace('/\n*\[HANDOFF_NEEDED\]\n*/', '', $content);

        return trim($content);
    }

    /**
     * Vérifie si l'utilisateur demande explicitement à parler à un humain
     * Backup en cas où l'IA n'ajoute pas le marqueur [HANDOFF_NEEDED]
     */
    private function checkUserRequestsHuman(string $userContent): bool
    {
        $content = mb_strtolower($userContent);

        // Patterns explicites de demande de contact humain
        $patterns = [
            // Demandes directes
            'parler à un humain',
            'parler a un humain',
            'parler à quelqu\'un',
            'parler a quelqu\'un',
            'parler avec un humain',
            'parler avec quelqu\'un',
            'je veux parler',
            'je voudrais parler',
            'puis-je parler',
            'puis je parler',
            'je peux parler',
            'est-ce que je peux parler',
            'est ce que je peux parler',

            // Demandes de conseiller/expert
            'un conseiller',
            'un expert',
            'une personne',
            'un humain',
            'un agent',
            'quelqu\'un de',
            'quelqu\'un qui',

            // Refus de l'IA
            'pas un robot',
            'pas une ia',
            'pas une intelligence artificielle',
            'vrai personne',
            'vraie personne',
            'personne réelle',
            'personne reelle',

            // Contact
            'contacter quelqu\'un',
            'contacter le support',
            'contacter un support',
            'contacter support',
            'support humain',
            'support client',
            'service client',
            'joindre quelqu\'un',
            'joindre le support',
            'joindre un conseiller',
            'être rappelé',
            'etre rappelé',
            'etre rappele',
            'me rappeler',
            'appeler',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($content, $pattern)) {
                Log::debug('ProcessAiMessageJob: User explicit handoff request detected', [
                    'pattern' => $pattern,
                    'content_preview' => substr($userContent, 0, 100),
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Déclenche l'escalade vers le support humain
     */
    private function triggerEscalation(\App\Models\AiSession $session, string $reason, ?float $maxRagScore = null): void
    {
        // Ne pas escalader si déjà escaladé
        if ($session->isEscalated()) {
            Log::info('ProcessAiMessageJob: Session already escalated, skipping', [
                'session_id' => $session->id,
            ]);
            return;
        }

        try {
            $escalationService = app(EscalationService::class);
            $escalationService->escalate($session, $reason, $maxRagScore);

            Log::info('ProcessAiMessageJob: Session escalated to human support', [
                'session_id' => $session->id,
                'reason' => $reason,
                'max_rag_score' => $maxRagScore,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessAiMessageJob: Failed to escalate session', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extrait le score RAG maximum depuis le contexte de la réponse
     */
    private function extractMaxRagScore(array $raw): float
    {
        $maxScore = 0.0;

        $context = $raw['context'] ?? [];

        // Vérifier les sources apprises (learned responses)
        $learnedSources = $context['learned_sources'] ?? [];
        foreach ($learnedSources as $source) {
            // Les scores sont stockés en pourcentage (0-100), convertir en 0-1
            $score = ($source['score'] ?? 0) / 100;
            $maxScore = max($maxScore, $score);
        }

        // Vérifier les sources documentaires (RAG)
        $documentSources = $context['document_sources'] ?? [];
        foreach ($documentSources as $source) {
            // Les scores sont stockés en pourcentage (0-100), convertir en 0-1
            $score = ($source['score'] ?? 0) / 100;
            $maxScore = max($maxScore, $score);
        }

        return $maxScore;
    }
}
