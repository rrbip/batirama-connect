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
use Illuminate\Support\Str;

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

    /**
     * Indexe les chunks au format Q/R Atomique.
     * Crée N points Q/R (un par question/réponse) + 1 point source par chunk.
     */
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

        $allPoints = [];
        $chunkPointMapping = [];
        $indexedChunks = 0;
        $skippedChunks = 0;

        foreach ($chunks as $chunk) {
            // Vérifier que le chunk a des knowledge_units (format Q/R Atomique)
            if (empty($chunk->knowledge_units)) {
                Log::debug('IndexDocumentChunksJob: Chunk without knowledge_units, skipping', [
                    'chunk_id' => $chunk->id,
                    'chunk_index' => $chunk->chunk_index,
                ]);
                $skippedChunks++;
                continue;
            }

            try {
                $pointsData = $this->buildQrAtomiquePoints($chunk, $embeddingService, $agent);
                $allPoints = array_merge($allPoints, $pointsData['points']);
                $chunkPointMapping[$chunk->id] = $pointsData['point_ids'];
                $indexedChunks++;

            } catch (\Exception $e) {
                Log::warning('Failed to build Q/R Atomique points for chunk', [
                    'document_id' => $this->document->id,
                    'chunk_index' => $chunk->chunk_index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($skippedChunks > 0) {
            Log::warning('IndexDocumentChunksJob: Some chunks skipped (no knowledge_units)', [
                'document_id' => $this->document->id,
                'skipped' => $skippedChunks,
                'indexed' => $indexedChunks,
            ]);
        }

        if (empty($allPoints)) {
            Log::warning('IndexDocumentChunksJob: No points to index', [
                'document_id' => $this->document->id,
            ]);
            return;
        }

        // Upsert en batch
        foreach (array_chunk($allPoints, 50) as $batch) {
            $success = $qdrantService->upsert($collection, $batch);

            if (!$success) {
                throw new \RuntimeException("Échec de l'upsert dans Qdrant");
            }
        }

        // Mettre à jour les chunks avec les IDs des points
        foreach ($chunks as $chunk) {
            if (isset($chunkPointMapping[$chunk->id])) {
                $chunk->update([
                    'qdrant_point_ids' => $chunkPointMapping[$chunk->id],
                    'qdrant_points_count' => count($chunkPointMapping[$chunk->id]),
                    'is_indexed' => true,
                    'indexed_at' => now(),
                ]);
            }
        }

        Log::info('Chunks indexed in Qdrant (Q/R Atomique format)', [
            'document_id' => $this->document->id,
            'points_count' => count($allPoints),
            'chunks_indexed' => $indexedChunks,
            'collection' => $collection,
        ]);
    }

    /**
     * Construit les points Q/R Atomique pour un chunk.
     * @return array{points: array, point_ids: array}
     */
    private function buildQrAtomiquePoints(
        DocumentChunk $chunk,
        EmbeddingService $embeddingService,
        $agent
    ): array {
        $points = [];
        $pointIds = [];

        $documentTitle = $this->document->title ?? $this->document->original_name ?? 'Document';
        $category = $chunk->category?->name ?? 'Non catégorisé';
        $parentContext = $chunk->parent_context ?? '';

        // 1. Créer les points Q/R (un par question/réponse)
        foreach ($chunk->knowledge_units as $index => $unit) {
            $question = $unit['question'] ?? null;
            $answer = $unit['answer'] ?? null;

            if (!$question || !$answer) {
                continue;
            }

            $pointId = Str::uuid()->toString();
            $pointIds[] = $pointId;

            // Embedding de la question
            $vector = $embeddingService->embed($question);

            $points[] = [
                'id' => $pointId,
                'vector' => $vector,
                'payload' => [
                    'type' => 'qa_pair',
                    'category' => $category,
                    'display_text' => $answer,
                    'question' => $question,
                    'source_doc' => $documentTitle,
                    'parent_context' => $parentContext,
                    'chunk_id' => $chunk->id,
                    'document_id' => $this->document->id,
                    'agent_id' => $agent->id,
                    'indexed_at' => now()->toIso8601String(),
                ],
            ];
        }

        // 2. Créer le point source (résumé + contenu)
        $summary = $chunk->summary ?? '';
        $content = $chunk->content ?? '';
        $sourceText = trim($summary . "\n\n" . $content);

        if (!empty($sourceText)) {
            $pointId = Str::uuid()->toString();
            $pointIds[] = $pointId;

            $vector = $embeddingService->embed($sourceText);

            $points[] = [
                'id' => $pointId,
                'vector' => $vector,
                'payload' => [
                    'type' => 'source_material',
                    'category' => $category,
                    'display_text' => $content,
                    'summary' => $summary,
                    'source_doc' => $documentTitle,
                    'parent_context' => $parentContext,
                    'chunk_id' => $chunk->id,
                    'document_id' => $this->document->id,
                    'agent_id' => $agent->id,
                    'indexed_at' => now()->toIso8601String(),
                ],
            ];
        }

        return [
            'points' => $points,
            'point_ids' => $pointIds,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('IndexDocumentChunksJob failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
