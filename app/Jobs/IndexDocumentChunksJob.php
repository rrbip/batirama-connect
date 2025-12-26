<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
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

class IndexDocumentChunksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 600;

    public function __construct(
        public Document $document
    ) {
        $this->onQueue('default');
    }

    public function handle(
        EmbeddingService $embeddingService,
        QdrantService $qdrantService
    ): void {
        Log::info('IndexDocumentChunksJob started', [
            'document_id' => $this->document->id,
        ]);

        try {
            $chunks = $this->document->chunks()->where('is_indexed', false)->get();

            if ($chunks->isEmpty()) {
                Log::info('No chunks to index', [
                    'document_id' => $this->document->id,
                ]);
                return;
            }

            $this->indexChunks($chunks, $embeddingService, $qdrantService);

            // Marquer le document comme indexé
            $this->document->update([
                'is_indexed' => true,
                'indexed_at' => now(),
                'extraction_status' => 'completed',
            ]);

            Log::info('IndexDocumentChunksJob completed', [
                'document_id' => $this->document->id,
                'chunks_indexed' => $chunks->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('IndexDocumentChunksJob failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function indexChunks(
        $chunks,
        EmbeddingService $embeddingService,
        QdrantService $qdrantService
    ): void {
        $agent = $this->document->agent;
        if (!$agent) {
            throw new \RuntimeException("Document sans agent associé");
        }

        $collection = $agent->qdrant_collection;
        if (empty($collection)) {
            throw new \RuntimeException("L'agent n'a pas de collection Qdrant configurée");
        }

        // S'assurer que la collection existe
        $qdrantService->ensureCollectionExists($collection);

        $points = [];
        $chunkPointMapping = [];

        foreach ($chunks as $chunk) {
            try {
                $vector = $embeddingService->embed($chunk->content);

                $pointId = Uuid::uuid5(
                    Uuid::NAMESPACE_DNS,
                    sprintf('document:%s:chunk:%d', $this->document->uuid, $chunk->chunk_index)
                )->toString();

                // Construire le payload avec les nouvelles métadonnées LLM
                $payload = [
                    'content' => $chunk->content,
                    'document_id' => $this->document->id,
                    'document_uuid' => $this->document->uuid,
                    'document_title' => $this->document->title ?? $this->document->original_name,
                    'chunk_index' => $chunk->chunk_index,
                    'category' => $this->document->category,
                    'source_type' => 'document',
                    'indexed_at' => now()->toIso8601String(),
                ];

                // Ajouter les métadonnées LLM si disponibles
                if (!empty($chunk->summary)) {
                    $payload['summary'] = $chunk->summary;
                }
                if (!empty($chunk->keywords)) {
                    $payload['keywords'] = $chunk->keywords;
                }
                if ($chunk->category) {
                    $payload['chunk_category'] = $chunk->category->name;
                    $payload['chunk_category_id'] = $chunk->category_id;
                }

                $points[] = [
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => $payload,
                ];

                $chunkPointMapping[$chunk->id] = $pointId;

            } catch (\Exception $e) {
                Log::warning('Failed to embed chunk', [
                    'document_id' => $this->document->id,
                    'chunk_index' => $chunk->chunk_index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($points)) {
            throw new \RuntimeException("Aucun embedding généré");
        }

        // Upsert en batch
        foreach (array_chunk($points, 50) as $batch) {
            $success = $qdrantService->upsert($collection, $batch);

            if (!$success) {
                throw new \RuntimeException("Échec de l'upsert dans Qdrant");
            }
        }

        // Mettre à jour les chunks
        foreach ($chunks as $chunk) {
            if (isset($chunkPointMapping[$chunk->id])) {
                $chunk->update([
                    'qdrant_point_id' => $chunkPointMapping[$chunk->id],
                    'is_indexed' => true,
                    'indexed_at' => now(),
                ]);
            }
        }

        Log::info('Chunks indexed in Qdrant', [
            'document_id' => $this->document->id,
            'points_count' => count($points),
            'collection' => $collection,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('IndexDocumentChunksJob failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
