<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Services\StructuredOutput\StructuredOutputParser;

class PromptBuilder
{
    private HydrationService $hydrationService;
    private StructuredOutputParser $structuredOutputParser;

    public function __construct(
        HydrationService $hydrationService,
        ?StructuredOutputParser $structuredOutputParser = null
    ) {
        $this->hydrationService = $hydrationService;
        $this->structuredOutputParser = $structuredOutputParser ?? new StructuredOutputParser();
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

        // Ajouter les instructions de handoff humain si activé
        $systemContent .= $agent->getHandoffInstructions();

        // Ajouter les instructions de structured output si activées
        $systemContent .= $this->getStructuredOutputInstructions($agent, $session);

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

    /**
     * Formate le contexte RAG pour inclusion dans le prompt.
     * Supporte le format Q/R Atomique avec display_text, category, source_doc, etc.
     */
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
                // Format Q/R Atomique : display_text en priorité
                $content = $result['payload']['display_text'] ?? $result['content'] ?? '';
            }

            // Extraire les métadonnées du payload (format Q/R Atomique)
            $payload = $result['payload'] ?? [];
            $type = $payload['type'] ?? null;
            $category = $payload['category'] ?? null;
            $sourceDoc = $payload['source_doc'] ?? null;
            $parentContext = $payload['parent_context'] ?? null;
            $question = $payload['question'] ?? null;
            $summary = $payload['summary'] ?? null;

            // Construire l'en-tête avec les métadonnées
            $header = "### Source {$num} (pertinence: {$score}%)";

            // Ajouter la catégorie si présente
            if ($category) {
                $header .= " [{$category}]";
            }

            // Ajouter le titre du document source
            if ($sourceDoc) {
                $header .= "\n**Document:** {$sourceDoc}";
                if ($parentContext) {
                    $header .= " > {$parentContext}";
                }
            }

            // Pour les Q/R pairs, afficher la question associée
            if ($type === 'qa_pair' && $question) {
                $header .= "\n**Question associée:** {$question}";
            }

            // Ajouter le résumé si présent (pour source_material)
            if ($type === 'source_material' && $summary) {
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
     * Tronque le contexte pour respecter la limite de tokens.
     * Supporte le format Q/R Atomique (display_text).
     */
    public function truncateToTokenLimit(array $ragResults, int $maxTokens = 4000): array
    {
        $totalTokens = 0;
        $truncatedResults = [];

        foreach ($ragResults as $result) {
            // Format Q/R Atomique : display_text en priorité
            $content = $result['payload']['display_text'] ?? $result['content'] ?? '';
            $contentTokens = $this->estimateTokens($content);

            if ($totalTokens + $contentTokens > $maxTokens) {
                break;
            }

            $truncatedResults[] = $result;
            $totalTokens += $contentTokens;
        }

        return $truncatedResults;
    }

    /**
     * Retourne les instructions de structured output si activées.
     *
     * Les instructions sont ajoutées si:
     * - L'agent a structured_output_enabled dans sa config
     * - La session est une session whitelabel (deployment_id présent)
     */
    private function getStructuredOutputInstructions(Agent $agent, ?AiSession $session): string
    {
        // Vérifier si structured output est activé pour cet agent
        $config = $agent->whitelabel_config ?? [];
        $structuredOutputEnabled = $config['structured_output_enabled'] ?? false;

        // Vérifier aussi si la session est whitelabel
        $isWhitelabelSession = $session && $session->deployment_id !== null;

        // Activer le structured output si:
        // 1. Explicitement activé dans la config
        // 2. OU session whitelabel (pour le cas concret des éditeurs)
        if (!$structuredOutputEnabled && !$isWhitelabelSession) {
            return '';
        }

        // Déterminer le type d'output selon le contexte
        $outputType = $config['structured_output_type'] ?? StructuredOutputParser::TYPE_PRE_QUOTE;

        return $this->structuredOutputParser->getPromptInstructions($outputType);
    }

    /**
     * Parse une réponse pour extraire le structured output.
     *
     * Utilisé après génération de la réponse IA pour extraire
     * les données structurées (pré-devis, projets, etc.)
     */
    public function parseStructuredOutput(string $content): ?array
    {
        return $this->structuredOutputParser->parse($content);
    }

    /**
     * Parse et valide spécifiquement un pré-devis.
     */
    public function parsePreQuote(string $content): ?array
    {
        return $this->structuredOutputParser->parsePreQuote($content);
    }
}
