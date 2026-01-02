<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\AI\LLMResponse;
use App\Events\Support\NewSupportMessage;
use App\Jobs\ProcessAiMessageJob;
use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DispatcherService
{
    public function __construct(
        private RagService $ragService,
        private LearningService $learningService,
        private EmbeddingService $embeddingService
    ) {}

    /**
     * Dispatch une question vers l'agent approprié
     */
    public function dispatch(
        string $userMessage,
        Agent $agent,
        ?User $user = null,
        ?AiSession $session = null
    ): LLMResponse {
        // Créer ou récupérer la session
        if (!$session) {
            $session = $this->createSession($agent, $user);
        }

        // Sauvegarder le message utilisateur
        $this->ragService->saveMessage($session, 'user', $userMessage);

        // Exécuter le RAG complet (inclut maintenant les learned responses comme contexte)
        $response = $this->ragService->query($agent, $userMessage, $session);

        // Log si des réponses apprises ont été utilisées comme contexte
        $learnedCount = $response->raw['learned_context']['count'] ?? 0;
        if ($learnedCount > 0) {
            Log::info('Learned responses used as context', [
                'agent' => $agent->slug,
                'learned_count' => $learnedCount,
            ]);
        }

        // Sauvegarder la réponse
        $this->ragService->saveMessage($session, 'assistant', $response->content, $response);

        // Mettre à jour le compteur de messages
        $session->increment('message_count');

        return $response;
    }

    /**
     * Dispatch une question de manière asynchrone
     * Retourne immédiatement avec l'ID du message en attente
     */
    public function dispatchAsync(
        string $userMessage,
        Agent $agent,
        ?User $user = null,
        ?AiSession $session = null,
        ?string $source = 'api'
    ): AiMessage {
        // Créer ou récupérer la session
        if (!$session) {
            $session = $this->createSession($agent, $user, $source);
        }

        // Sauvegarder le message utilisateur
        $this->ragService->saveMessage($session, 'user', $userMessage);

        // Rafraîchir la session pour avoir le statut actuel (peut avoir été escaladée entre-temps)
        $session->refresh();

        // Vérifier si la session est en mode support (escalated ou assigned)
        $isInSupportMode = in_array($session->support_status, ['escalated', 'assigned']);

        Log::debug('DispatcherService: Checking support mode', [
            'session_id' => $session->id,
            'support_status' => $session->support_status,
            'is_in_support_mode' => $isInSupportMode,
        ]);

        // Si la session est en mode support, créer aussi un message support pour notifier les agents
        if ($isInSupportMode) {
            $this->createSupportMessageForEscalatedSession($session, $userMessage);
        }

        // Créer le message assistant en attente (contenu vide pour l'instant)
        $assistantMessage = AiMessage::create([
            'uuid' => Str::uuid()->toString(),
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => '',
            'processing_status' => AiMessage::STATUS_PENDING,
            'created_at' => now(),
        ]);

        Log::info('DispatcherService: Creating async message', [
            'message_id' => $assistantMessage->id,
            'message_uuid' => $assistantMessage->uuid,
            'session_id' => $session->id,
            'agent' => $agent->slug,
        ]);

        // Dispatcher le job de traitement
        $job = new ProcessAiMessageJob($assistantMessage, $userMessage);
        dispatch($job);

        // Mettre à jour le statut comme "queued"
        $assistantMessage->markAsQueued();

        Log::info('DispatcherService: Job dispatched', [
            'message_id' => $assistantMessage->id,
            'message_uuid' => $assistantMessage->uuid,
            'queue' => 'ai-messages',
        ]);

        return $assistantMessage;
    }

    /**
     * Crée une nouvelle session de chat
     */
    public function createSession(Agent $agent, ?User $user = null, string $source = 'admin_test'): AiSession
    {
        return AiSession::create([
            'uuid' => Str::uuid()->toString(),
            'agent_id' => $agent->id,
            'user_id' => $user?->id,
            'tenant_id' => $agent->tenant_id,
            'external_context' => [
                'agent_slug' => $agent->slug,
                'source' => $source,
            ],
            'status' => 'active',
        ]);
    }

    /**
     * Route vers le meilleur agent pour une question donnée
     */
    public function routeToAgent(string $userMessage, ?int $tenantId = null): ?Agent
    {
        // Récupérer les agents actifs
        $agents = Agent::where('is_active', true)
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->get();

        if ($agents->isEmpty()) {
            return null;
        }

        if ($agents->count() === 1) {
            return $agents->first();
        }

        // Scoring simple basé sur les mots-clés
        $bestAgent = null;
        $bestScore = 0;

        foreach ($agents as $agent) {
            $score = $this->calculateAgentScore($userMessage, $agent);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAgent = $agent;
            }
        }

        return $bestAgent ?? $agents->first();
    }

    /**
     * Calcule un score de pertinence pour un agent
     */
    private function calculateAgentScore(string $message, Agent $agent): float
    {
        $score = 0.0;
        $messageLower = Str::lower($message);

        // Mots-clés du système prompt
        $systemPromptWords = Str::of($agent->system_prompt)
            ->lower()
            ->explode(' ')
            ->filter(fn($word) => strlen($word) > 4)
            ->unique();

        foreach ($systemPromptWords as $word) {
            if (Str::contains($messageLower, $word)) {
                $score += 1.0;
            }
        }

        // Bonus si le nom de l'agent est mentionné
        if (Str::contains($messageLower, Str::lower($agent->name))) {
            $score += 5.0;
        }

        // Bonus basé sur le slug
        if (Str::contains($messageLower, str_replace('-', ' ', $agent->slug))) {
            $score += 3.0;
        }

        return $score;
    }

    /**
     * Récupère l'historique d'une session
     * Inclut les messages IA et les messages de support (système, agent)
     */
    public function getSessionHistory(AiSession $session, int $limit = 50): array
    {
        $isHumanSupportActive = in_array($session->support_status, ['escalated', 'assigned']);

        // Messages IA (filtrer les non-validés si support humain actif)
        $aiMessagesQuery = $session->messages();

        if ($isHumanSupportActive) {
            $aiMessagesQuery->where(function ($query) {
                $query->where('role', 'user')
                      ->orWhere(function ($q) {
                          $q->where('role', 'assistant')
                            ->whereIn('validation_status', ['validated', 'learned']);
                      });
            });
        }

        $aiMessages = $aiMessagesQuery
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->take($limit)
            ->get()
            ->map(fn($msg) => [
                'role' => $msg->role,
                'content' => $msg->corrected_content ?? $msg->content,
                'created_at' => $msg->created_at,
            ]);

        // Messages de support (agent, system)
        $supportMessages = $session->supportMessages()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($msg) => [
                'role' => $msg->sender_type === 'agent' ? 'support' : 'system',
                'content' => $msg->content,
                'sender_name' => $msg->sender?->name ?? null,
                'created_at' => $msg->created_at,
            ]);

        // Fusionner et trier par date
        return $aiMessages->concat($supportMessages)
            ->sortBy('created_at')
            ->values()
            ->map(fn($msg) => [
                'role' => $msg['role'],
                'content' => $msg['content'],
                'sender_name' => $msg['sender_name'] ?? null,
                'created_at' => $msg['created_at']->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Termine une session
     */
    public function endSession(AiSession $session): void
    {
        $session->update([
            'ended_at' => now(),
            'status' => 'completed',
        ]);
    }

    /**
     * Crée un message support pour une session escaladée.
     * Ceci permet de notifier les agents support quand l'utilisateur envoie un message
     * via le chat standalone après escalade.
     */
    protected function createSupportMessageForEscalatedSession(AiSession $session, string $content): void
    {
        try {
            $message = SupportMessage::create([
                'session_id' => $session->id,
                'sender_type' => 'user',
                'channel' => 'chat',
                'content' => $content,
                'is_read' => false,
            ]);

            // Mettre à jour l'activité de la session
            $session->touch('last_activity_at');

            // Dispatcher l'événement pour notifier les agents support
            event(new NewSupportMessage($message));

            Log::info('DispatcherService: Support message created for escalated session', [
                'session_id' => $session->id,
                'message_id' => $message->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('DispatcherService: Failed to create support message', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
