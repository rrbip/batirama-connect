<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use App\Services\DocumentChunkerService;
use App\Services\DocumentExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 600; // 10 minutes max

    public function __construct(
        public Document $document,
        public bool $reindex = false
    ) {}

    public function handle(
        DocumentExtractorService $extractor,
        DocumentChunkerService $chunker,
        EmbeddingService $embeddingService,
        QdrantService $qdrantService
    ): void {
        Log::info('Processing document', [
            'document_id' => $this->document->id,
            'document_name' => $this->document->original_name,
        ]);

        try {
            // 1. Mettre à jour le statut
            $this->document->update(['extraction_status' => 'processing']);

            // 2. Extraire le texte
            $extractedText = $extractor->extract($this->document);

            if (empty($extractedText)) {
                throw new \RuntimeException("Aucun texte extrait du document");
            }

            // Fusionner avec les métadonnées existantes (ex: vision_extraction, ocr_extraction)
            $existingMetadata = $this->document->extraction_metadata ?? [];
            $this->document->update([
                'extracted_text' => $extractedText,
                'extraction_metadata' => array_merge($existingMetadata, [
                    'text_length' => mb_strlen($extractedText),
                    'estimated_tokens' => (int) ceil(mb_strlen($extractedText) / 4),
                ]),
                'extracted_at' => now(),
            ]);

            Log::info('Text extracted', [
                'document_id' => $this->document->id,
                'text_length' => mb_strlen($extractedText),
            ]);

            // 3. Découper en chunks
            // Si stratégie LLM, dispatcher vers le job dédié
            if ($this->document->chunk_strategy === 'llm_assisted') {
                Log::info('Dispatching to LLM chunking', [
                    'document_id' => $this->document->id,
                ]);

                ProcessLlmChunkingJob::dispatch($this->document, reindex: true);

                // Le job LLM s'occupera du chunking et de l'indexation
                return;
            }

            $chunks = $chunker->chunk($this->document);

            Log::info('Document chunked', [
                'document_id' => $this->document->id,
                'chunk_count' => count($chunks),
            ]);

            // 4. Indexer dans Qdrant
            $this->indexChunks($chunks, $embeddingService, $qdrantService);

            // 5. Marquer comme terminé
            $this->document->update([
                'extraction_status' => 'completed',
                'is_indexed' => true,
                'indexed_at' => now(),
            ]);

            Log::info('Document processed successfully', [
                'document_id' => $this->document->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Document processing failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->document->update([
                'extraction_status' => 'failed',
                'extraction_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Indexe les chunks dans Qdrant
     */
    private function indexChunks(
        array $chunks,
        EmbeddingService $embeddingService,
        QdrantService $qdrantService
    ): void {
        if (empty($chunks)) {
            return;
        }

        // Récupérer l'agent pour la collection
        $agent = $this->document->agent;
        if (!$agent) {
            throw new \RuntimeException("Document sans agent associé");
        }

        $collection = $agent->qdrant_collection;
        if (empty($collection)) {
            throw new \RuntimeException("L'agent n'a pas de collection Qdrant configurée");
        }

        Log::info('Starting chunk indexation', [
            'document_id' => $this->document->id,
            'collection' => $collection,
            'chunk_count' => count($chunks),
        ]);

        // S'assurer que la collection existe
        $qdrantService->ensureCollectionExists($collection);

        // Préparer les points pour l'upsert en batch
        $points = [];
        $chunkPointMapping = []; // Pour mettre à jour les chunks APRÈS l'upsert

        foreach ($chunks as $chunk) {
            try {
                // Générer l'embedding
                $vector = $embeddingService->embed($chunk->content);

                // Créer un UUID v5 valide pour Qdrant (déterministe basé sur document+chunk)
                $pointId = Uuid::uuid5(
                    Uuid::NAMESPACE_DNS,
                    sprintf('document:%s:chunk:%d', $this->document->uuid, $chunk->chunk_index)
                )->toString();

                $points[] = [
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => [
                        'content' => $chunk->content,
                        'document_id' => $this->document->id,
                        'document_uuid' => $this->document->uuid,
                        'document_title' => $this->document->title ?? $this->document->original_name,
                        'chunk_index' => $chunk->chunk_index,
                        'category' => $this->document->category,
                        'source_type' => 'document',
                        'indexed_at' => now()->toIso8601String(),
                    ],
                ];

                // Stocker le mapping chunk -> pointId pour mise à jour après upsert
                $chunkPointMapping[$chunk->id] = $pointId;

            } catch (\Exception $e) {
                Log::warning('Failed to embed chunk', [
                    'document_id' => $this->document->id,
                    'chunk_index' => $chunk->chunk_index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Upsert en batch
        if (!empty($points)) {
            $allSuccess = true;

            // Découper en lots de 50 pour éviter les timeouts
            foreach (array_chunk($points, 50) as $batch) {
                $success = $qdrantService->upsert($collection, $batch);

                if (!$success) {
                    $allSuccess = false;
                    Log::error('Failed to upsert batch to Qdrant', [
                        'document_id' => $this->document->id,
                        'batch_size' => count($batch),
                    ]);
                }
            }

            // Mettre à jour les chunks SEULEMENT si l'upsert a réussi
            if ($allSuccess) {
                foreach ($chunks as $chunk) {
                    if (isset($chunkPointMapping[$chunk->id])) {
                        $chunk->update([
                            'qdrant_point_id' => $chunkPointMapping[$chunk->id],
                            'is_indexed' => true,
                            'indexed_at' => now(),
                        ]);
                    }
                }

                Log::info('Chunks indexed in Qdrant successfully', [
                    'document_id' => $this->document->id,
                    'points_count' => count($points),
                    'collection' => $collection,
                ]);
            } else {
                throw new \RuntimeException("Échec de l'indexation dans Qdrant");
            }
        }
    }

    /**
     * Gestion des erreurs - supprimer les chunks partiels
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessDocumentJob failed definitively', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        // Nettoyer les chunks partiellement créés
        $this->document->chunks()->delete();

        $this->document->update([
            'extraction_status' => 'failed',
            'extraction_error' => $exception->getMessage(),
            'chunk_count' => 0,
            'is_indexed' => false,
        ]);
    }
}
