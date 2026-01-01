<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\AI\LLMResponse;
use App\DTOs\AI\RagResult;
use App\Events\Chat\UserMessageReceived;
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
        private CategoryDetectionService $categoryDetectionService,
        private IndexingStrategyService $indexingStrategyService
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

        // 3. Vérifier si on a une réponse Q/R directe (score > 0.95)
        $directQr = $this->findDirectQrResponse($ragResults);
        if ($directQr !== null) {
            return $this->buildDirectQrResponse($directQr, $agent, $categoryDetection);
        }

        // 4. Hydratation SQL si configurée
        if ($agent->usesHydration() && !empty($ragResults)) {
            $ragResults = $this->hydrationService->hydrate(
                $ragResults,
                $agent->hydration_config ?? []
            );
        }

        // 5. Recherche itérative si activée et résultats insuffisants
        if ($agent->allow_iterative_search && count($ragResults) < 3) {
            $additionalResults = $this->iterativeSearch($agent, $userMessage, $ragResults);
            $ragResults = array_merge($ragResults, $additionalResults);
        }

        // 6. Tronquer si nécessaire pour respecter le contexte
        $ragResults = $this->promptBuilder->truncateToTokenLimit(
            $ragResults,
            $agent->getContextTokenLimit()
        );

        // 7. Construire le prompt avec TOUT le contexte et générer la réponse
        $llmService = LLMServiceFactory::forAgent($agent);

        $messages = $this->promptBuilder->buildChatMessages(
            agent: $agent,
            userMessage: $userMessage,
            ragResults: $ragResults,
            session: $session,
            learnedResponses: $learnedResponses
        );

        $response = $llmService->chat($messages, [
            'temperature' => $agent->temperature,
            'max_tokens' => $agent->max_tokens,
            'fallback_model' => $agent->fallback_model,
        ]);

        // 8. Construire le contexte complet pour sauvegarde (validation humaine)
        $fullContext = $this->buildFullContext(
            agent: $agent,
            messages: $messages,
            learnedResponses: $learnedResponses,
            ragResults: $ragResults,
            session: $session,
            categoryDetection: $categoryDetection
        );

        // 9. Ajouter les métadonnées à la réponse
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

            // Sources: Documents RAG (format Q/R Atomique)
            'document_sources' => collect($ragResults)->map(fn ($r, $i) => [
                'index' => $i + 1,
                'id' => $r['id'] ?? null,
                'score' => round(($r['score'] ?? 0) * 100, 1),
                'type' => $r['payload']['type'] ?? 'unknown',
                'content' => $r['payload']['display_text'] ?? $r['content'] ?? '',
                'question' => $r['payload']['question'] ?? null,
                'category' => $r['payload']['category'] ?? null,
                'source_doc' => $r['payload']['source_doc'] ?? null,
                'metadata' => array_diff_key($r['payload'] ?? [], ['display_text' => true, 'question' => true]),
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
     * Cherche une réponse Q/R directe parmi les résultats (score > 0.95).
     */
    private function findDirectQrResponse(array $ragResults): ?array
    {
        foreach ($ragResults as $result) {
            if ($this->indexingStrategyService->isDirectQrResult($result)) {
                return $this->indexingStrategyService->extractDirectAnswer($result);
            }
        }
        return null;
    }

    /**
     * Construit une réponse directe depuis un Q/R match (sans appel LLM).
     */
    private function buildDirectQrResponse(array $qr, Agent $agent, ?array $categoryDetection): LLMResponse
    {
        $answer = $qr['answer'];

        Log::info('RagService: Direct Q/R response used', [
            'agent' => $agent->slug,
            'question_matched' => $qr['question'],
            'score' => $qr['score'],
            'source' => $qr['source'],
            'is_faq' => $qr['is_faq'],
        ]);

        // Construire le contexte complet pour le rapport d'analyse
        $fullContext = [
            'system_prompt_sent' => '',
            'conversation_history' => [],
            'learned_sources' => [],
            'document_sources' => [
                [
                    'index' => 1,
                    'id' => $qr['point_id'] ?? null,
                    'score' => round(($qr['score'] ?? 0) * 100, 1),
                    'type' => $qr['is_faq'] ? 'faq' : 'qa_pair',
                    'content' => $qr['answer'],
                    'question' => $qr['question'],
                    'category' => $qr['category'],
                    'source_doc' => $qr['source'],
                ],
            ],
            'category_detection' => $categoryDetection,
            'stats' => [
                'learned_count' => 0,
                'document_count' => 1,
                'history_count' => 0,
                'context_window_size' => $agent->context_window_size,
                'agent_slug' => $agent->slug,
                'agent_model' => 'direct_qr_match',
                'temperature' => 0,
                'use_category_filtering' => $agent->use_category_filtering ?? false,
                'response_type' => 'direct_qr_match',
                'direct_qr_threshold' => IndexingStrategyService::DIRECT_QR_THRESHOLD,
            ],
        ];

        return new LLMResponse(
            content: $answer,
            model: 'direct_qr_match',
            tokensPrompt: 0,
            tokensCompletion: 0,
            generationTimeMs: 0,
            raw: [
                'direct_qr' => true,
                'matched_question' => $qr['question'],
                'score' => $qr['score'],
                'source' => $qr['source'],
                'category' => $qr['category'],
                'is_faq' => $qr['is_faq'],
                'context' => $fullContext,
            ]
        );
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

            // Fallback: seulement si confiance faible ET pas assez de résultats
            // Avec confiance élevée (>= 70%), on garde UNIQUEMENT les résultats de la catégorie
            $confidenceThreshold = 0.70;
            $confidence = $categoryDetection['confidence'] ?? 0;
            $shouldUseFallback = !empty($categoryFilter)
                && count($results) < 2
                && $confidence < $confidenceThreshold;

            if ($shouldUseFallback) {
                $usedFallback = true;

                Log::info('RAG fallback: low confidence category detection, searching without filter', [
                    'agent' => $agent->slug,
                    'filtered_count' => count($results),
                    'confidence' => $confidence,
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
            } elseif (!empty($categoryFilter) && count($results) < 2 && $confidence >= $confidenceThreshold) {
                // Confiance élevée mais peu de résultats : on garde le filtrage strict
                Log::info('RAG strict filtering: high confidence, keeping category-only results', [
                    'agent' => $agent->slug,
                    'filtered_count' => count($results),
                    'confidence' => $confidence,
                    'detected_categories' => collect($categoryDetection['categories'] ?? [])->pluck('name')->toArray(),
                ]);
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
                'results_types' => collect($results)->pluck('payload.type')->filter()->toArray(),
                'results_categories' => collect($results)->pluck('payload.category')->filter()->toArray(),
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

        // Broadcast l'événement si c'est un message utilisateur
        // Permet à l'admin de voir le message immédiatement
        if ($role === 'user') {
            Log::info('Broadcasting UserMessageReceived', [
                'message_id' => $message->uuid,
                'session_id' => $session->uuid,
                'channel' => 'chat.session.' . $session->uuid,
            ]);
            broadcast(new UserMessageReceived($message));
        }

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
