<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\AiSession;

class PromptBuilder
{
    private HydrationService $hydrationService;

    public function __construct(HydrationService $hydrationService)
    {
        $this->hydrationService = $hydrationService;
    }

    /**
     * Construit le prompt complet pour l'agent
     */
    public function build(
        Agent $agent,
        string $userMessage,
        array $ragResults = [],
        ?AiSession $session = null
    ): string {
        $parts = [];

        // 1. System prompt de l'agent
        $parts[] = $this->buildSystemSection($agent);

        // 2. Contexte RAG
        if (!empty($ragResults)) {
            $parts[] = $this->buildContextSection($ragResults, $agent);
        }

        // 3. Historique de conversation
        if ($session && $agent->context_window_size > 0) {
            $history = $this->buildHistorySection($session, $agent->context_window_size);
            if (!empty($history)) {
                $parts[] = $history;
            }
        }

        // 4. Question de l'utilisateur
        $parts[] = $this->buildUserSection($userMessage);

        return implode("\n\n", $parts);
    }

    /**
     * Construit les messages pour l'API chat
     */
    public function buildChatMessages(
        Agent $agent,
        string $userMessage,
        array $ragResults = [],
        ?AiSession $session = null,
        array $learnedResponses = []
    ): array {
        $messages = [];

        // System message avec contexte intégré
        $systemContent = $agent->system_prompt;

        // Ajouter les garde-fous si strict_mode est activé
        $systemContent .= $agent->getStrictModeGuardrails();

        // Ajouter les réponses apprises similaires (priorité haute)
        if (!empty($learnedResponses)) {
            $learnedContent = $this->formatLearnedResponses($learnedResponses);
            $systemContent .= "\n\n## CAS SIMILAIRES TRAITÉS\n\nVoici des échanges similaires précédemment validés. Inspire-toi de ces réponses pour formuler ta réponse :\n\n{$learnedContent}";
        }

        // Ajouter le contexte documentaire RAG
        if (!empty($ragResults)) {
            $contextContent = $this->formatRagContext($ragResults, $agent);
            $systemContent .= "\n\n## CONTEXTE DOCUMENTAIRE\n\n{$contextContent}";
        }

        $messages[] = [
            'role' => 'system',
            'content' => $systemContent,
        ];

        // Historique de conversation
        if ($session && $agent->context_window_size > 0) {
            $historyMessages = $this->getHistoryMessages($session, $agent->context_window_size);
            $messages = array_merge($messages, $historyMessages);
        }

        // Message utilisateur actuel
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        return $messages;
    }

    /**
     * Formate les réponses apprises pour le contexte
     */
    private function formatLearnedResponses(array $learnedResponses): string
    {
        $parts = [];

        foreach ($learnedResponses as $index => $learned) {
            $num = $index + 1;
            $score = round(($learned['score'] ?? 0) * 100);
            $question = $learned['question'] ?? '';
            $answer = $learned['answer'] ?? '';

            $parts[] = "### Cas {$num} (similarité: {$score}%)\n**Question:** {$question}\n**Réponse validée:** {$answer}";
        }

        return implode("\n\n", $parts);
    }

    private function buildSystemSection(Agent $agent): string
    {
        return "## INSTRUCTIONS SYSTÈME\n\n{$agent->system_prompt}";
    }

    private function buildContextSection(array $ragResults, Agent $agent): string
    {
        $contextContent = $this->formatRagContext($ragResults, $agent);

        return "## CONTEXTE DOCUMENTAIRE\n\nUtilise les informations suivantes pour répondre à la question.\n**IMPORTANT:** Privilégie les sources dont la catégorie correspond au sujet de la question. Ignore les sources hors-sujet même si elles ont un score de pertinence élevé.\nSi l'information n'est pas dans le contexte, indique-le clairement.\n\n{$contextContent}";
    }

    private function formatRagContext(array $ragResults, Agent $agent): string
    {
        $contextParts = [];

        foreach ($ragResults as $index => $result) {
            $num = $index + 1;
            $score = round($result['score'] * 100);

            // Si hydratation SQL activée et données disponibles
            if ($agent->usesHydration() && isset($result['hydrated_data'])) {
                $content = $this->hydrationService->formatForContext($result);
            } else {
                $content = $result['payload']['content'] ?? $result['content'] ?? '';
            }

            // Extraire les métadonnées utiles du payload
            $payload = $result['payload'] ?? [];
            $documentTitle = $payload['document_title'] ?? null;
            $chunkCategory = $payload['chunk_category'] ?? null;
            $summary = $payload['summary'] ?? null;

            // Construire l'en-tête avec les métadonnées
            $header = "### Source {$num} (pertinence: {$score}%)";

            // Ajouter la catégorie si présente
            if ($chunkCategory) {
                $header .= " [Catégorie: {$chunkCategory}]";
            }

            // Ajouter le titre du document si présent
            if ($documentTitle) {
                $header .= "\n**Document:** {$documentTitle}";
            }

            // Ajouter le résumé si présent (aide l'IA à comprendre rapidement)
            if ($summary) {
                $header .= "\n**Résumé:** {$summary}";
            }

            $contextParts[] = "{$header}\n{$content}";
        }

        return implode("\n\n", $contextParts);
    }

    private function buildHistorySection(AiSession $session, int $windowSize): string
    {
        $messages = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id', 'desc') // Récupérer les plus récents par ID
            ->take($windowSize * 2)
            ->get()
            ->sortBy('id') // Trier par ID croissant pour ordre chronologique
            ->values();

        if ($messages->isEmpty()) {
            return '';
        }

        $historyParts = ["## HISTORIQUE DE CONVERSATION"];

        foreach ($messages as $message) {
            $role = $message->role === 'user' ? 'Utilisateur' : 'Assistant';
            $content = $this->truncateContent($message->content, 500);
            $historyParts[] = "{$role}: {$content}";
        }

        return implode("\n\n", $historyParts);
    }

    private function getHistoryMessages(AiSession $session, int $windowSize): array
    {
        $messages = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id', 'desc') // Récupérer les plus récents par ID
            ->take($windowSize * 2)
            ->get()
            ->sortBy('id') // Trier par ID croissant pour ordre chronologique
            ->values();

        return $messages->map(fn (AiMessage $msg) => [
            'role' => $msg->role,
            'content' => $msg->content,
        ])->toArray();
    }

    private function buildUserSection(string $userMessage): string
    {
        return "## QUESTION DE L'UTILISATEUR\n\n{$userMessage}";
    }

    private function truncateContent(string $content, int $maxLength): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        return substr($content, 0, $maxLength) . '...';
    }

    /**
     * Estime le nombre de tokens d'un texte
     */
    public function estimateTokens(string $text): int
    {
        // Estimation approximative : 1 token ≈ 4 caractères en français
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Tronque le contexte pour respecter la limite de tokens
     */
    public function truncateToTokenLimit(array $ragResults, int $maxTokens = 4000): array
    {
        $totalTokens = 0;
        $truncatedResults = [];

        foreach ($ragResults as $result) {
            $content = $result['payload']['content'] ?? $result['content'] ?? '';
            $contentTokens = $this->estimateTokens($content);

            if ($totalTokens + $contentTokens > $maxTokens) {
                break;
            }

            $truncatedResults[] = $result;
            $totalTokens += $contentTokens;
        }

        return $truncatedResults;
    }
}
