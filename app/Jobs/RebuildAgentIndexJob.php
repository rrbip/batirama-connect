<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\DocumentChunk;
use App\Models\LearnedResponse;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job de reconstruction de l'index Qdrant pour un agent.
 *
 * Ce job supprime tous les points de la collection Qdrant de l'agent
 * et les recrée à partir des chunks en base de données.
 *
 * Utilise le format Q/R Atomique:
 * - Points Q/R: un point par question/réponse (vecteur = embedding de la question)
 * - Point source: un point par chunk (vecteur = embedding du résumé + contenu)
 */
class RebuildAgentIndexJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600; // 1 heure max

    public function __construct(
        public Agent $agent
    ) {
        $this->onQueue('default');
    }

    public function handle(
        QdrantService $qdrantService,
        EmbeddingService $embeddingService
    ): void {
        $collection = $this->agent->qdrant_collection;

        if (empty($collection)) {
            Log::error('RebuildAgentIndexJob: Agent has no Qdrant collection', [
                'agent_id' => $this->agent->id,
            ]);

            return;
        }

        Log::info('RebuildAgentIndexJob: Starting index rebuild', [
            'agent_id' => $this->agent->id,
            'agent_name' => $this->agent->name,
            'collection' => $collection,
        ]);

        try {
            // 1. Supprimer et recréer la collection
            $this->resetCollection($qdrantService, $collection);

            // 2. Récupérer tous les chunks "useful" de l'agent avec leurs relations
            $chunks = DocumentChunk::whereHas('document', function ($query) {
                $query->where('agent_id', $this->agent->id);
            })
                ->where('useful', true)
                ->with(['document', 'category'])
                ->get();

            Log::info('RebuildAgentIndexJob: Found useful chunks to index', [
                'agent_id' => $this->agent->id,
                'chunk_count' => $chunks->count(),
            ]);

            if ($chunks->isEmpty()) {
                Log::info('RebuildAgentIndexJob: No useful chunks to index', [
                    'agent_id' => $this->agent->id,
                ]);

                return;
            }

            // 3. Indexer les chunks avec le format Q/R Atomique
            $stats = $this->indexChunksQrAtomique($chunks, $qdrantService, $embeddingService, $collection);

            // 4. Reconstruire les learned_responses (FAQs validées)
            $learnedStats = $this->rebuildLearnedResponses($qdrantService, $embeddingService, $collection);

            Log::info('RebuildAgentIndexJob: Index rebuild completed', [
                'agent_id' => $this->agent->id,
                'agent_name' => $this->agent->name,
                'collection' => $collection,
                'chunks_processed' => $chunks->count(),
                'qa_points' => $stats['qa_points'],
                'source_points' => $stats['source_points'],
                'total_points' => $stats['total_points'],
                'learned_responses' => $learnedStats['count'],
            ]);

        } catch (\Exception $e) {
            Log::error('RebuildAgentIndexJob: Failed to rebuild index', [
                'agent_id' => $this->agent->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Supprime et recrée la collection Qdrant.
     */
    private function resetCollection(QdrantService $qdrantService, string $collection): void
    {
        // Supprimer la collection si elle existe
        if ($qdrantService->collectionExists($collection)) {
            $qdrantService->deleteCollection($collection);
            Log::info('RebuildAgentIndexJob: Collection deleted', [
                'collection' => $collection,
            ]);
        }

        // Recréer la collection
        $config = [
            'vector_size' => config('ai.qdrant.vector_size', 768),
            'distance' => config('ai.qdrant.distance', 'Cosine'),
        ];

        $qdrantService->createCollection($collection, $config);

        // Recréer les index pour le format Q/R Atomique
        $indexes = [
            'type' => 'keyword',           // qa_pair ou source_material
            'category' => 'keyword',       // catégorie du chunk
            'document_id' => 'integer',
            'chunk_id' => 'integer',
            'agent_id' => 'integer',
        ];

        foreach ($indexes as $field => $type) {
            $qdrantService->createPayloadIndex($collection, $field, $type);
        }

        Log::info('RebuildAgentIndexJob: Collection recreated with Q/R Atomique indexes', [
            'collection' => $collection,
        ]);
    }

    /**
     * Indexe les chunks dans Qdrant avec le format Q/R Atomique.
     *
     * Pour chaque chunk:
     * - N points Q/R (un par question/réponse)
     * - 1 point source (résumé + contenu)
     */
    private function indexChunksQrAtomique(
        $chunks,
        QdrantService $qdrantService,
        EmbeddingService $embeddingService,
        string $collection
    ): array {
        $stats = ['qa_points' => 0, 'source_points' => 0, 'total_points' => 0];
        $batchSize = 20; // Plus petit car on génère plusieurs points par chunk
        $batches = $chunks->chunk($batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $points = [];

            foreach ($batch as $chunk) {
                try {
                    $chunkPoints = $this->buildPointsForChunk($chunk, $embeddingService);
                    $points = array_merge($points, $chunkPoints['points']);

                    $stats['qa_points'] += $chunkPoints['qa_count'];
                    $stats['source_points'] += $chunkPoints['source_count'];

                    // Mettre à jour le chunk avec les nouveaux point IDs
                    $chunk->update([
                        'qdrant_point_ids' => $chunkPoints['point_ids'],
                        'qdrant_points_count' => count($chunkPoints['point_ids']),
                        'is_indexed' => true,
                        'indexed_at' => now(),
                    ]);

                } catch (\Exception $e) {
                    Log::warning('RebuildAgentIndexJob: Failed to process chunk', [
                        'chunk_id' => $chunk->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! empty($points)) {
                $qdrantService->upsert($collection, $points);
                $stats['total_points'] += count($points);

                Log::debug('RebuildAgentIndexJob: Batch indexed', [
                    'batch' => $batchIndex + 1,
                    'points' => count($points),
                    'total' => $stats['total_points'],
                ]);
            }
        }

        // Mettre à jour les documents comme indexés
        $documentIds = $chunks->pluck('document_id')->unique();
        \App\Models\Document::whereIn('id', $documentIds)->update([
            'is_indexed' => true,
            'indexed_at' => now(),
        ]);

        return $stats;
    }

    /**
     * Construit les points Qdrant pour un chunk (format Q/R Atomique).
     */
    private function buildPointsForChunk(DocumentChunk $chunk, EmbeddingService $embeddingService): array
    {
        $points = [];
        $pointIds = [];
        $qaCount = 0;
        $sourceCount = 0;

        $document = $chunk->document;
        $categoryName = $chunk->category?->name ?? 'DIVERS';

        // 1. Créer les points Q/R à partir de knowledge_units
        $knowledgeUnits = $chunk->knowledge_units ?? [];

        foreach ($knowledgeUnits as $unit) {
            $question = $unit['question'] ?? '';
            $answer = $unit['answer'] ?? '';

            if (empty($question) || empty($answer)) {
                continue;
            }

            // Embedding sur la question
            $embedding = $embeddingService->embed($question);

            $pointId = Str::uuid()->toString();
            $pointIds[] = $pointId;

            $points[] = [
                'id' => $pointId,
                'vector' => $embedding,
                'payload' => [
                    'type' => 'qa_pair',
                    'category' => $categoryName,
                    'display_text' => $answer,
                    'question' => $question,
                    'source_doc' => $document->title ?? $document->original_name,
                    'parent_context' => $chunk->parent_context,
                    'chunk_id' => $chunk->id,
                    'document_id' => $document->id,
                    'agent_id' => $this->agent->id,
                ],
            ];

            $qaCount++;
        }

        // 2. Créer le point source (résumé + contenu)
        $summaryContent = ($chunk->summary ?? '') . ' ' . $chunk->content;
        $sourceEmbedding = $embeddingService->embed($summaryContent);

        $sourcePointId = Str::uuid()->toString();
        $pointIds[] = $sourcePointId;

        $points[] = [
            'id' => $sourcePointId,
            'vector' => $sourceEmbedding,
            'payload' => [
                'type' => 'source_material',
                'category' => $categoryName,
                'display_text' => $chunk->content,
                'summary' => $chunk->summary ?? '',
                'source_doc' => $document->title ?? $document->original_name,
                'parent_context' => $chunk->parent_context,
                'chunk_id' => $chunk->id,
                'document_id' => $document->id,
                'agent_id' => $this->agent->id,
            ],
        ];

        $sourceCount = 1;

        return [
            'points' => $points,
            'point_ids' => $pointIds,
            'qa_count' => $qaCount,
            'source_count' => $sourceCount,
        ];
    }

    /**
     * Reconstruit les learned_responses pour cet agent.
     *
     * Utilise la table PostgreSQL learned_responses comme source de vérité
     * et réindexe dans Qdrant (collection learned_responses ET collection de l'agent).
     */
    private function rebuildLearnedResponses(
        QdrantService $qdrantService,
        EmbeddingService $embeddingService,
        string $collection
    ): array {
        $learnedResponsesCollection = 'learned_responses';

        // 1. Supprimer les points existants pour cet agent dans learned_responses Qdrant
        $this->deleteAgentPointsFromLearnedResponses($qdrantService, $learnedResponsesCollection);

        // 2. Supprimer les FAQs existantes dans la collection de l'agent
        $this->deleteAgentFaqPoints($qdrantService, $collection);

        // 3. Récupérer toutes les LearnedResponse depuis PostgreSQL pour cet agent
        $learnedResponses = LearnedResponse::where('agent_id', $this->agent->id)
            ->with(['agent'])
            ->get();

        Log::info('RebuildAgentIndexJob: Found LearnedResponse records to reindex', [
            'agent_id' => $this->agent->id,
            'count' => $learnedResponses->count(),
        ]);

        if ($learnedResponses->isEmpty()) {
            return ['count' => 0];
        }

        $indexedCount = 0;

        foreach ($learnedResponses as $lr) {
            try {
                // Générer l'embedding de la question
                $vector = $embeddingService->embed($lr->question);
                $pointId = Str::uuid()->toString();

                // a) Indexer dans learned_responses Qdrant
                $this->ensureLearnedResponsesCollectionExists($qdrantService, $learnedResponsesCollection);

                $qdrantService->upsert($learnedResponsesCollection, [[
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => $lr->toQdrantPayload(),
                ]]);

                // Mettre à jour le qdrant_point_id dans PostgreSQL
                LearnedResponse::withoutEvents(function () use ($lr, $pointId) {
                    $lr->update(['qdrant_point_id' => $pointId]);
                });

                // b) Indexer aussi dans la collection de l'agent (comme FAQ)
                // UUID déterministe pour permettre la mise à jour/suppression
                $faqPointId = $this->generateFaqPointId($lr->id);
                $qdrantService->upsert($collection, [[
                    'id' => $faqPointId,
                    'vector' => $vector,
                    'payload' => [
                        'type' => 'qa_pair',
                        'category' => 'FAQ',
                        'display_text' => $lr->answer,
                        'question' => $lr->question,
                        'source_doc' => 'FAQ Validée',
                        'parent_context' => '',
                        'chunk_id' => null,
                        'document_id' => null,
                        'agent_id' => $this->agent->id,
                        'is_faq' => true,
                        'learned_response_id' => $lr->id,
                        'validation_count' => $lr->validation_count,
                        'rejection_count' => $lr->rejection_count,
                        'requires_handoff' => $lr->requires_handoff,
                        'indexed_at' => now()->toIso8601String(),
                    ],
                ]]);

                $indexedCount++;

            } catch (\Exception $e) {
                Log::warning('RebuildAgentIndexJob: Failed to reindex LearnedResponse', [
                    'learned_response_id' => $lr->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('RebuildAgentIndexJob: LearnedResponse records reindexed', [
            'agent_id' => $this->agent->id,
            'indexed' => $indexedCount,
        ]);

        return ['count' => $indexedCount];
    }

    /**
     * Supprime les points FAQ existants dans la collection de l'agent.
     */
    private function deleteAgentFaqPoints(QdrantService $qdrantService, string $collection): void
    {
        if (!$qdrantService->collectionExists($collection)) {
            return;
        }

        $filter = [
            'must' => [
                ['key' => 'is_faq', 'match' => ['value' => true]]
            ]
        ];

        $deleted = $qdrantService->deleteByFilter($collection, $filter);

        if ($deleted) {
            Log::info('RebuildAgentIndexJob: Deleted existing FAQ points from agent collection', [
                'agent_id' => $this->agent->id,
                'collection' => $collection,
            ]);
        }
    }

    /**
     * Supprime tous les points de cet agent dans la collection learned_responses.
     */
    private function deleteAgentPointsFromLearnedResponses(QdrantService $qdrantService, string $collection): void
    {
        if (!$qdrantService->collectionExists($collection)) {
            return;
        }

        $filter = [
            'must' => [
                ['key' => 'agent_id', 'match' => ['value' => $this->agent->id]]
            ]
        ];

        $deleted = $qdrantService->deleteByFilter($collection, $filter);

        if ($deleted) {
            Log::info('RebuildAgentIndexJob: Deleted existing learned_responses for agent', [
                'agent_id' => $this->agent->id,
                'collection' => $collection,
            ]);
        }
    }

    /**
     * S'assure que la collection learned_responses existe.
     */
    private function ensureLearnedResponsesCollectionExists(QdrantService $qdrantService, string $collection): void
    {
        if ($qdrantService->collectionExists($collection)) {
            return;
        }

        $config = [
            'vector_size' => config('ai.qdrant.vector_size', 768),
            'distance' => 'Cosine',
        ];

        $qdrantService->createCollection($collection, $config);

        // Créer les index
        $indexes = [
            'agent_id' => 'integer',
            'agent_slug' => 'keyword',
            'message_id' => 'integer',
        ];

        foreach ($indexes as $field => $type) {
            $qdrantService->createPayloadIndex($collection, $field, $type);
        }

        Log::info('RebuildAgentIndexJob: Created learned_responses collection', [
            'collection' => $collection,
        ]);
    }

    /**
     * Génère un UUID déterministe pour un point FAQ dans la collection de l'agent.
     * Doit être identique à LearnedResponseObserver::generateFaqPointId()
     */
    private function generateFaqPointId(int $learnedResponseId): string
    {
        $hash = md5('learned_response_faq_' . $learnedResponseId);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RebuildAgentIndexJob: Job failed permanently', [
            'agent_id' => $this->agent->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
