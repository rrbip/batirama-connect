<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\LlmChunkingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job pour enrichir les chunks existants avec le LLM
 *
 * Ajoute aux chunks : catégorie, keywords, summary
 * Utilisé après un chunking markdown pour ajouter les métadonnées sémantiques
 */
class EnrichChunksWithLlmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 0; // Pas de timeout (traitement long possible)

    public function __construct(
        public Document $document,
        public int $batchSize = 10,
        public bool $reindexAfter = true
    ) {
        $this->onQueue('llm-chunking');
    }

    public function handle(LlmChunkingService $service): void
    {
        Log::info('EnrichChunksWithLlmJob started', [
            'document_id' => $this->document->id,
            'batch_size' => $this->batchSize,
        ]);

        try {
            // Marquer le document comme en cours d'enrichissement
            $this->document->update([
                'extraction_status' => 'enriching',
            ]);

            // Enrichir les chunks
            $result = $service->enrichMarkdownChunks($this->document, $this->batchSize);

            // Marquer comme terminé
            $this->document->update([
                'extraction_status' => 'completed',
            ]);

            Log::info('EnrichChunksWithLlmJob completed', [
                'document_id' => $this->document->id,
                'enriched_count' => $result['enriched_count'],
                'new_categories' => $result['new_categories'],
            ]);

            // Ré-indexer si demandé
            if ($this->reindexAfter && $result['enriched_count'] > 0) {
                ProcessDocumentJob::dispatch($this->document, reindex: true);
            }

        } catch (\Exception $e) {
            Log::error('EnrichChunksWithLlmJob failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);

            $this->document->update([
                'extraction_status' => 'completed', // Garder completed car les chunks existent
                'extraction_error' => 'Enrichissement LLM échoué: ' . $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('EnrichChunksWithLlmJob failed definitively', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        $this->document->update([
            'extraction_status' => 'completed',
            'extraction_error' => 'Enrichissement LLM définitivement échoué: ' . $exception->getMessage(),
        ]);
    }
}
