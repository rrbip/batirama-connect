<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\AI\LLMResponse;
use App\Models\Agent;
use App\Models\AiSession;
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

        // Vérifier les réponses apprises
        $learnedResponse = $this->findLearnedResponse($userMessage, $agent);

        if ($learnedResponse) {
            Log::info('Using learned response', [
                'agent' => $agent->slug,
                'score' => $learnedResponse['score'],
            ]);

            $response = new LLMResponse(
                content: $learnedResponse['answer'],
                model: 'learned_response',
                raw: ['learned' => true, 'score' => $learnedResponse['score']]
            );
        } else {
            // Exécuter le RAG standard
            $response = $this->ragService->query($agent, $userMessage, $session);
        }

        // Sauvegarder la réponse
        $this->ragService->saveMessage($session, 'assistant', $response->content, $response);

        // Mettre à jour la session
        $session->update([
            'last_activity_at' => now(),
        ]);

        return $response;
    }

    /**
     * Cherche une réponse apprise similaire
     */
    private function findLearnedResponse(string $question, Agent $agent): ?array
    {
        $results = $this->learningService->findSimilarLearnedResponses(
            question: $question,
            agentSlug: $agent->slug,
            limit: 1,
            minScore: 0.90 // Score élevé pour les réponses apprises
        );

        return $results[0] ?? null;
    }

    /**
     * Crée une nouvelle session de chat
     */
    public function createSession(Agent $agent, ?User $user = null): AiSession
    {
        return AiSession::create([
            'uuid' => Str::uuid()->toString(),
            'agent_id' => $agent->id,
            'user_id' => $user?->id,
            'metadata' => [
                'agent_slug' => $agent->slug,
                'started_at' => now()->toIso8601String(),
            ],
            'started_at' => now(),
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
     */
    public function getSessionHistory(AiSession $session, int $limit = 50): array
    {
        return $session->messages()
            ->orderBy('created_at', 'asc')
            ->take($limit)
            ->get()
            ->map(fn($msg) => [
                'role' => $msg->role,
                'content' => $msg->content,
                'created_at' => $msg->created_at->toIso8601String(),
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
}
