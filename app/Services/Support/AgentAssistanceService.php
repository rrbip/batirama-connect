<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Agent;
use App\Models\AiSession;
use App\Services\AI\RagService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AgentAssistanceService
{
    protected RagService $ragService;

    public function __construct(RagService $ragService)
    {
        $this->ragService = $ragService;
    }

    /**
     * Suggère une réponse basée sur le contexte RAG.
     */
    public function suggestResponse(AiSession $session, string $userQuestion): ?array
    {
        $agent = $session->agent;

        if (!$agent) {
            return null;
        }

        try {
            // 1. Récupérer les sources RAG pertinentes
            $ragResults = $this->ragService->search($agent, $userQuestion, 5);

            if (empty($ragResults)) {
                return [
                    'suggested_response' => null,
                    'sources' => [],
                    'has_sources' => false,
                ];
            }

            // 2. Construire le contexte pour la génération
            $context = $this->buildContext($ragResults);

            // 3. Générer une réponse suggérée
            $suggestedResponse = $this->generateSuggestion(
                $agent,
                $userQuestion,
                $context
            );

            // 4. Formater les sources pour l'affichage
            $sources = array_map(fn($result) => [
                'title' => $result['title'] ?? 'Source',
                'content' => \Illuminate\Support\Str::limit($result['content'] ?? '', 200),
                'score' => $result['score'] ?? 0,
                'url' => $result['url'] ?? null,
            ], array_slice($ragResults, 0, 3));

            return [
                'suggested_response' => $suggestedResponse,
                'sources' => $sources,
                'has_sources' => true,
            ];

        } catch (\Throwable $e) {
            Log::error('Failed to suggest response', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Améliore une réponse rédigée par l'agent.
     */
    public function improveResponse(AiSession $session, string $agentDraft): ?string
    {
        $agent = $session->agent;

        if (!$agent) {
            return null;
        }

        try {
            $prompt = <<<PROMPT
Tu es un assistant qui améliore les réponses de support client.

Améliore la réponse suivante pour la rendre:
- Plus claire et concise
- Plus professionnelle
- Plus empathique envers l'utilisateur
- Grammaticalement correcte

Garde le même sens et les mêmes informations, mais améliore la formulation.

Réponse originale:
{$agentDraft}

Réponse améliorée:
PROMPT;

            $response = $this->callLLM($agent, $prompt);

            if (!$response) {
                return null;
            }

            // Nettoyer la réponse
            $improved = trim($response);

            // S'assurer que la réponse est différente de l'originale
            if ($improved === $agentDraft) {
                return null;
            }

            return $improved;

        } catch (\Throwable $e) {
            Log::error('Failed to improve response', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Génère un résumé de la conversation pour l'agent.
     */
    public function summarizeConversation(AiSession $session): ?string
    {
        $agent = $session->agent;

        if (!$agent) {
            return null;
        }

        $messages = $session->messages()
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return null;
        }

        try {
            $conversation = $messages->map(fn($m) =>
                ($m->role === 'user' ? 'Utilisateur' : 'IA') . ': ' . $m->content
            )->implode("\n\n");

            $prompt = <<<PROMPT
Résume cette conversation de support en 2-3 phrases.
Identifie:
- La demande principale de l'utilisateur
- Les points clés abordés
- Le statut actuel (résolu, en attente, etc.)

Conversation:
{$conversation}

Résumé:
PROMPT;

            return $this->callLLM($agent, $prompt);

        } catch (\Throwable $e) {
            Log::error('Failed to summarize conversation', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Construit le contexte à partir des résultats RAG.
     */
    protected function buildContext(array $ragResults): string
    {
        $contextParts = [];

        foreach ($ragResults as $result) {
            $title = $result['title'] ?? 'Document';
            $content = $result['content'] ?? '';

            if (!empty($content)) {
                $contextParts[] = "### {$title}\n{$content}";
            }
        }

        return implode("\n\n", $contextParts);
    }

    /**
     * Génère une suggestion de réponse.
     */
    protected function generateSuggestion(Agent $agent, string $question, string $context): ?string
    {
        $prompt = <<<PROMPT
Tu es un assistant qui aide les agents de support.

Basé sur les informations ci-dessous, génère une réponse professionnelle et complète pour répondre à la question de l'utilisateur.

**Contexte (informations de la base de connaissances):**
{$context}

**Question de l'utilisateur:**
{$question}

**Instructions:**
- Réponds de manière claire et professionnelle
- Utilise uniquement les informations fournies dans le contexte
- Si le contexte ne contient pas toutes les informations nécessaires, indique-le
- Termine par une formule de politesse

**Réponse suggérée:**
PROMPT;

        return $this->callLLM($agent, $prompt);
    }

    /**
     * Appelle le LLM pour générer du texte.
     */
    protected function callLLM(Agent $agent, string $prompt): ?string
    {
        $host = $agent->ollama_host ?? config('ai.ollama.host', 'ollama');
        $port = $agent->ollama_port ?? config('ai.ollama.port', 11434);
        $model = $agent->model ?? 'mistral:7b';

        try {
            $response = Http::timeout(60)
                ->post("http://{$host}:{$port}/api/generate", [
                    'model' => $model,
                    'prompt' => $prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.7,
                        'num_predict' => 1024,
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('LLM call failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            return $response->json('response');

        } catch (\Throwable $e) {
            Log::error('LLM call error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
