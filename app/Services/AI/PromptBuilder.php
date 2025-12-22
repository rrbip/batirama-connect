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
        ?AiSession $session = null
    ): array {
        $messages = [];

        // System message avec contexte RAG intégré
        $systemContent = $agent->system_prompt;

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

    private function buildSystemSection(Agent $agent): string
    {
        return "## INSTRUCTIONS SYSTÈME\n\n{$agent->system_prompt}";
    }

    private function buildContextSection(array $ragResults, Agent $agent): string
    {
        $contextContent = $this->formatRagContext($ragResults, $agent);

        return "## CONTEXTE DOCUMENTAIRE\n\nUtilise les informations suivantes pour répondre à la question. Si l'information n'est pas dans le contexte, indique-le clairement.\n\n{$contextContent}";
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

            $contextParts[] = "### Source {$num} (pertinence: {$score}%)\n{$content}";
        }

        return implode("\n\n", $contextParts);
    }

    private function buildHistorySection(AiSession $session, int $windowSize): string
    {
        $messages = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at', 'desc')
            ->take($windowSize * 2)
            ->get()
            ->reverse();

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
            ->orderBy('created_at', 'desc')
            ->take($windowSize * 2)
            ->get()
            ->reverse();

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
