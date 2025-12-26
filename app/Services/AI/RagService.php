<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\AI\LLMResponse;
use App\DTOs\AI\RagResult;
use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\AiSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RagService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private QdrantService $qdrantService,
        private OllamaService $ollamaService,
        private HydrationService $hydrationService,
        private PromptBuilder $promptBuilder,
        private LearningService $learningService,
        private CategoryDetectionService $categoryDetectionService
    ) {}

    /**
     * Traite une question utilisateur avec RAG complet
     */
    public function query(
        Agent $agent,
        string $userMessage,
        ?AiSession $session = null
    ): LLMResponse {
        // 1. Recherche des réponses apprises similaires (priorité haute)
        $learnedResponses = $this->learningService->findSimilarLearnedResponses(
            question: $userMessage,
            agentSlug: $agent->slug,
            limit: $agent->getMaxLearnedResponses(),
            minScore: $agent->getLearnedMinScore()
        );

        // 2. Recherche dans la base vectorielle documentaire (avec détection catégorie)
        $retrieval = $this->retrieveContextWithDetection($agent, $userMessage);
        $ragResults = $retrieval['results'];
        $categoryDetection = $retrieval['category_detection'];

        // 3. Hydratation SQL si configurée
        if ($agent->usesHydration() && !empty($ragResults)) {
            $ragResults = $this->hydrationService->hydrate(
                $ragResults,
                $agent->hydration_config ?? []
            );
        }

        // 4. Recherche itérative si activée et résultats insuffisants
        if ($agent->allow_iterative_search && count($ragResults) < 3) {
            $additionalResults = $this->iterativeSearch($agent, $userMessage, $ragResults);
            $ragResults = array_merge($ragResults, $additionalResults);
        }

        // 5. Tronquer si nécessaire pour respecter le contexte
        $ragResults = $this->promptBuilder->truncateToTokenLimit(
            $ragResults,
            $agent->getContextTokenLimit()
        );

        // 6. Construire le prompt avec TOUT le contexte et générer la réponse
        $ollama = OllamaService::forAgent($agent);

        $messages = $this->promptBuilder->buildChatMessages(
            agent: $agent,
            userMessage: $userMessage,
            ragResults: $ragResults,
            session: $session,
            learnedResponses: $learnedResponses
        );

        $response = $ollama->chat($messages, [
            'temperature' => $agent->temperature,
            'max_tokens' => $agent->max_tokens,
            'fallback_model' => $agent->fallback_model,
        ]);

        // 7. Construire le contexte complet pour sauvegarde (validation humaine)
        $fullContext = $this->buildFullContext(
            agent: $agent,
            messages: $messages,
            learnedResponses: $learnedResponses,
            ragResults: $ragResults,
            session: $session,
            categoryDetection: $categoryDetection
        );

        // 8. Ajouter les métadonnées à la réponse
        $response = new LLMResponse(
            content: $response->content,
            model: $response->model,
            tokensPrompt: $response->tokensPrompt,
            tokensCompletion: $response->tokensCompletion,
            generationTimeMs: $response->generationTimeMs,
            raw: array_merge($response->raw, [
                'context' => $fullContext,
            ])
        );

        return $response;
    }

    /**
     * Construit le contexte complet pour sauvegarde et validation humaine
     */
    private function buildFullContext(
        Agent $agent,
        array $messages,
        array $learnedResponses,
        array $ragResults,
        ?AiSession $session = null,
        ?array $categoryDetection = null
    ): array {
        // Extraire le system prompt envoyé
        $systemPrompt = collect($messages)
            ->firstWhere('role', 'system')['content'] ?? '';

        // Extraire l'historique de conversation (fenêtre glissante)
        $conversationHistory = [];
        if ($session && $agent->context_window_size > 0) {
            $historyMessages = $session->messages()
                ->whereIn('role', ['user', 'assistant'])
                ->where('processing_status', AiMessage::STATUS_COMPLETED)
                ->orderBy('id', 'desc') // Récupérer les plus récents par ID (ordre de création)
                ->take($agent->context_window_size * 2)
                ->get()
                ->sortBy('id') // Trier par ID croissant pour ordre chronologique
                ->values();

            $conversationHistory = $historyMessages->map(fn (AiMessage $msg) => [
                'role' => $msg->role,
                'content' => $msg->content,
                'timestamp' => $msg->created_at->format('H:i:s'),
            ])->values()->toArray();
        }

        return [
            // Le prompt système complet envoyé au LLM
            'system_prompt_sent' => $systemPrompt,

            // Historique de conversation (fenêtre glissante)
            'conversation_history' => $conversationHistory,

            // Sources: Cas similaires traités (learned responses)
            'learned_sources' => collect($learnedResponses)->map(fn ($r, $i) => [
                'index' => $i + 1,
                'score' => round(($r['score'] ?? 0) * 100, 1),
                'question' => $r['question'] ?? '',
                'answer' => $r['answer'] ?? '',
                'message_id' => $r['message_id'] ?? null,
            ])->values()->toArray(),

            // Sources: Documents RAG
            'document_sources' => collect($ragResults)->map(fn ($r, $i) => [
                'index' => $i + 1,
                'id' => $r['id'] ?? null,
                'score' => round(($r['score'] ?? 0) * 100, 1),
                'content' => $r['payload']['content'] ?? $r['content'] ?? '',
                'metadata' => array_diff_key($r['payload'] ?? [], ['content' => true]),
            ])->values()->toArray(),

            // Détection de catégorie pour le filtrage RAG
            'category_detection' => $categoryDetection,

            // Statistiques
            'stats' => [
                'learned_count' => count($learnedResponses),
                'document_count' => count($ragResults),
                'history_count' => count($conversationHistory),
                'context_window_size' => $agent->context_window_size,
                'agent_slug' => $agent->slug,
                'agent_model' => $agent->getModel(),
                'temperature' => $agent->temperature,
                'use_category_filtering' => $agent->use_category_filtering ?? false,
            ],
        ];
    }

    /**
     * Recherche les documents pertinents dans Qdrant (version simple pour rétrocompatibilité)
     */
    public function retrieveContext(Agent $agent, string $query): array
    {
        return $this->retrieveContextWithDetection($agent, $query)['results'];
    }

    /**
     * Recherche les documents pertinents dans Qdrant avec infos de détection de catégorie
     *
     * @return array{results: array, category_detection: array|null}
     */
    public function retrieveContextWithDetection(Agent $agent, string $query): array
    {
        $categoryDetection = null;
        $usedFallback = false;

        try {
            // Vérifier que l'agent a une collection configurée
            if (empty($agent->qdrant_collection)) {
                Log::info('RAG skipped: No Qdrant collection configured', [
                    'agent' => $agent->slug,
                ]);
                return [
                    'results' => [],
                    'category_detection' => null,
                ];
            }

            // Générer l'embedding de la requête
            $queryVector = $this->embeddingService->embed($query);

            $minScore = $agent->getMinRagScore();
            $limit = $agent->max_rag_results ?? config('ai.rag.max_results', 5);

            // Détecter la catégorie de la question pour pré-filtrer
            $categoryFilter = [];

            if ($agent->use_category_filtering) {
                $categoryDetection = $this->categoryDetectionService->detect($query, $agent);

                if ($categoryDetection['categories']->isNotEmpty()) {
                    $categoryFilter = $this->categoryDetectionService->buildQdrantFilter(
                        $categoryDetection['categories']
                    );

                    // Convertir les catégories en array sérialisable
                    $categoryDetection['categories'] = $categoryDetection['categories']->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'slug' => $c->slug,
                    ])->values()->toArray();

                    Log::info('RAG category filter applied', [
                        'agent' => $agent->slug,
                        'detected_categories' => collect($categoryDetection['categories'])->pluck('name')->toArray(),
                        'detection_method' => $categoryDetection['method'],
                        'confidence' => $categoryDetection['confidence'],
                    ]);
                } else {
                    $categoryDetection['categories'] = [];
                }
            }

            Log::info('RAG search starting', [
                'agent' => $agent->slug,
                'collection' => $agent->qdrant_collection,
                'query' => $query,
                'min_score' => $minScore,
                'limit' => $limit,
                'has_category_filter' => !empty($categoryFilter),
            ]);

            // Rechercher avec filtre de catégorie si disponible
            $results = $this->qdrantService->search(
                vector: $queryVector,
                collection: $agent->qdrant_collection,
                limit: $limit,
                filter: $categoryFilter,
                scoreThreshold: $minScore
            );

            $filteredCount = count($results);

            // Si pas assez de résultats avec le filtre, refaire une recherche sans filtre
            if (!empty($categoryFilter) && count($results) < 2) {
                $usedFallback = true;

                Log::info('RAG fallback: not enough filtered results, searching without filter', [
                    'agent' => $agent->slug,
                    'filtered_count' => count($results),
                ]);

                $unfilteredResults = $this->qdrantService->search(
                    vector: $queryVector,
                    collection: $agent->qdrant_collection,
                    limit: $limit,
                    filter: [],
                    scoreThreshold: $minScore
                );

                // Fusionner les résultats en gardant les filtrés en priorité
                $existingIds = collect($results)->pluck('id')->toArray();
                $additionalResults = collect($unfilteredResults)
                    ->filter(fn($r) => !in_array($r['id'], $existingIds))
                    ->take($limit - count($results))
                    ->toArray();

                $results = array_merge($results, $additionalResults);
            }

            // Ajouter les infos de fallback à la détection
            if ($categoryDetection !== null) {
                $categoryDetection['used_fallback'] = $usedFallback;
                $categoryDetection['filtered_results_count'] = $filteredCount;
                $categoryDetection['total_results_count'] = count($results);
            }

            Log::info('RAG search completed', [
                'agent' => $agent->slug,
                'results_count' => count($results),
                'results_scores' => collect($results)->pluck('score')->toArray(),
                'results_categories' => collect($results)->pluck('payload.chunk_category')->filter()->toArray(),
            ]);

            return [
                'results' => $results,
                'category_detection' => $categoryDetection,
            ];

        } catch (\Exception $e) {
            Log::error('RAG retrieval failed', [
                'agent' => $agent->slug,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            return [
                'results' => [],
                'category_detection' => $categoryDetection,
            ];
        }
    }

    /**
     * Recherche itérative pour améliorer les résultats
     */
    private function iterativeSearch(
        Agent $agent,
        string $originalQuery,
        array $existingResults
    ): array {
        // Reformuler la question pour une recherche alternative
        $reformulatedQuery = $this->reformulateQuery($originalQuery);

        if ($reformulatedQuery === $originalQuery) {
            return [];
        }

        try {
            $queryVector = $this->embeddingService->embed($reformulatedQuery);

            $additionalResults = $this->qdrantService->search(
                vector: $queryVector,
                collection: $agent->qdrant_collection,
                limit: 3,
                scoreThreshold: $agent->getMinRagScore()
            );

            // Filtrer les doublons
            $existingIds = collect($existingResults)->pluck('id')->toArray();

            return collect($additionalResults)
                ->filter(fn ($r) => !in_array($r['id'], $existingIds))
                ->values()
                ->toArray();

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Reformule une question pour recherche alternative
     */
    private function reformulateQuery(string $query): string
    {
        // Simplification basique - peut être amélioré avec un LLM
        $query = Str::lower($query);

        // Supprimer les mots interrogatifs
        $query = preg_replace(
            '/^(comment|quel|quelle|quels|quelles|combien|pourquoi|est-ce que)\s+/i',
            '',
            $query
        );

        // Supprimer la ponctuation finale
        $query = rtrim($query, '?!.');

        return trim($query);
    }

    /**
     * Formate les métadonnées RAG pour stockage
     */
    private function formatRagMetadata(array $ragResults): array
    {
        return [
            'sources' => collect($ragResults)->map(fn ($r) => [
                'id' => $r['id'] ?? null,
                'score' => $r['score'] ?? 0,
                'content' => Str::limit($r['payload']['content'] ?? '', 200),
            ])->toArray(),
            'retrieval_count' => count($ragResults),
        ];
    }

    /**
     * Sauvegarde un message dans la session
     */
    public function saveMessage(
        AiSession $session,
        string $role,
        string $content,
        ?LLMResponse $response = null,
        array $attachments = []
    ): AiMessage {
        $messageData = [
            'uuid' => Str::uuid()->toString(),
            'session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'attachments' => !empty($attachments) ? $attachments : null,
            'created_at' => now(),
        ];

        if ($role === 'assistant' && $response) {
            $messageData['model_used'] = $response->model;
            $messageData['used_fallback_model'] = $response->usedFallback;
            $messageData['tokens_prompt'] = $response->tokensPrompt;
            $messageData['tokens_completion'] = $response->tokensCompletion;
            $messageData['generation_time_ms'] = $response->generationTimeMs;
            // Sauvegarder le contexte complet pour validation humaine
            $messageData['rag_context'] = $response->raw['context'] ?? null;
        }

        $message = AiMessage::create($messageData);

        // Mettre à jour le compteur de messages
        $session->increment('message_count');

        return $message;
    }

    /**
     * Indexe un document dans Qdrant
     */
    public function indexDocument(
        string $collection,
        string $id,
        string $content,
        array $payload = []
    ): bool {
        try {
            $vector = $this->embeddingService->embed($content);

            return $this->qdrantService->upsert($collection, [
                [
                    'id' => $id,
                    'vector' => $vector,
                    'payload' => array_merge($payload, [
                        'content' => $content,
                        'indexed_at' => now()->toIso8601String(),
                    ]),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Document indexing failed', [
                'collection' => $collection,
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Indexe plusieurs documents en batch
     */
    public function indexBatch(string $collection, array $documents): int
    {
        $successful = 0;

        foreach (array_chunk($documents, 50) as $chunk) {
            $points = [];

            foreach ($chunk as $doc) {
                try {
                    $vector = $this->embeddingService->embed($doc['content']);

                    $points[] = [
                        'id' => $doc['id'],
                        'vector' => $vector,
                        'payload' => array_merge($doc['payload'] ?? [], [
                            'content' => $doc['content'],
                            'indexed_at' => now()->toIso8601String(),
                        ]),
                    ];
                } catch (\Exception $e) {
                    Log::warning('Failed to embed document', [
                        'id' => $doc['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (!empty($points) && $this->qdrantService->upsert($collection, $points)) {
                $successful += count($points);
            }
        }

        return $successful;
    }
}
