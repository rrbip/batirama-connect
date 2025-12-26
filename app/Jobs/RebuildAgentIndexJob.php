<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Agent;
use App\Models\DocumentChunk;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Job de reconstruction de l'index Qdrant pour un agent.
 *
 * Ce job supprime tous les points de la collection Qdrant de l'agent
 * et les recrée à partir des chunks en base de données.
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

            // 2. Récupérer tous les chunks de l'agent
            $chunks = DocumentChunk::whereHas('document', function ($query) {
                $query->where('agent_id', $this->agent->id);
            })->with('document')->get();

            Log::info('RebuildAgentIndexJob: Found chunks to index', [
                'agent_id' => $this->agent->id,
                'chunk_count' => $chunks->count(),
            ]);

            if ($chunks->isEmpty()) {
                Log::info('RebuildAgentIndexJob: No chunks to index', [
                    'agent_id' => $this->agent->id,
                ]);

                return;
            }

            // 3. Indexer les chunks par batch
            $this->indexChunks($chunks, $qdrantService, $embeddingService, $collection);

            Log::info('RebuildAgentIndexJob: Index rebuild completed', [
                'agent_id' => $this->agent->id,
                'agent_name' => $this->agent->name,
                'collection' => $collection,
                'chunks_indexed' => $chunks->count(),
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

        // Recréer les index
        $indexes = [
            'document_id' => 'integer',
            'document_uuid' => 'keyword',
            'category' => 'keyword',
            'source_type' => 'keyword',
        ];

        foreach ($indexes as $field => $type) {
            $qdrantService->createPayloadIndex($collection, $field, $type);
        }

        Log::info('RebuildAgentIndexJob: Collection recreated', [
            'collection' => $collection,
        ]);
    }

    /**
     * Indexe les chunks dans Qdrant.
     */
    private function indexChunks(
        $chunks,
        QdrantService $qdrantService,
        EmbeddingService $embeddingService,
        string $collection
    ): void {
        $batchSize = 50;
        $batches = $chunks->chunk($batchSize);
        $totalIndexed = 0;

        foreach ($batches as $batchIndex => $batch) {
            $points = [];

            foreach ($batch as $chunk) {
                try {
                    // Générer l'embedding
                    $vector = $embeddingService->embed($chunk->content);

                    // Générer un ID déterministe
                    $pointId = Uuid::uuid5(
                        Uuid::NAMESPACE_DNS,
                        sprintf('document:%s:chunk:%d', $chunk->document->uuid, $chunk->chunk_index)
                    )->toString();

                    // Construire le payload
                    $payload = [
                        'content' => $chunk->content,
                        'document_id' => $chunk->document->id,
                        'document_uuid' => $chunk->document->uuid,
                        'document_title' => $chunk->document->title ?? $chunk->document->original_name,
                        'chunk_index' => $chunk->chunk_index,
                        'category' => $chunk->document->category,
                        'source_type' => 'document',
                        'indexed_at' => now()->toIso8601String(),
                    ];

                    // Ajouter les métadonnées LLM si disponibles
                    if (! empty($chunk->summary)) {
                        $payload['summary'] = $chunk->summary;
                    }
                    if (! empty($chunk->keywords)) {
                        $payload['keywords'] = $chunk->keywords;
                    }

                    $points[] = [
                        'id' => $pointId,
                        'vector' => $vector,
                        'payload' => $payload,
                    ];

                    // Mettre à jour le chunk avec le nouveau point ID
                    $chunk->update([
                        'qdrant_point_id' => $pointId,
                        'is_indexed' => true,
                        'indexed_at' => now(),
                    ]);

                } catch (\Exception $e) {
                    Log::warning('RebuildAgentIndexJob: Failed to embed chunk', [
                        'chunk_id' => $chunk->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! empty($points)) {
                $qdrantService->upsert($collection, $points);
                $totalIndexed += count($points);

                Log::debug('RebuildAgentIndexJob: Batch indexed', [
                    'batch' => $batchIndex + 1,
                    'points' => count($points),
                    'total' => $totalIndexed,
                ]);
            }
        }

        // Mettre à jour les documents comme indexés
        $documentIds = $chunks->pluck('document_id')->unique();
        \App\Models\Document::whereIn('id', $documentIds)->update([
            'is_indexed' => true,
            'indexed_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RebuildAgentIndexJob: Job failed permanently', [
            'agent_id' => $this->agent->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
