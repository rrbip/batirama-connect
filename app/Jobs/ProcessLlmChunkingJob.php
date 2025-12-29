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

class ProcessLlmChunkingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Nombre de tentatives
     */
    public int $tries;

    /**
     * Pas de timeout - le job peut prendre longtemps
     */
    public int $timeout = 0;

    /**
     * Ne pas réessayer en cas d'échec
     */
    public bool $failOnTimeout = false;

    public function __construct(
        public Document $document,
        public bool $reindex = true
    ) {
        $this->onQueue('llm-chunking');

        // Récupérer le nombre de retries depuis les settings
        $this->tries = \App\Models\LlmChunkingSetting::getInstance()->max_retries;
    }

    public function handle(LlmChunkingService $service): void
    {
        Log::info('ProcessLlmChunkingJob started', [
            'document_id' => $this->document->id,
            'document_title' => $this->document->title ?? $this->document->original_name,
        ]);

        // Validation du contenu AVANT d'appeler le LLM
        $text = $this->document->extracted_text;

        if (empty($text)) {
            $this->document->update([
                'extraction_status' => 'failed',
                'extraction_error' => 'Document vide : aucun texte extrait. Vérifiez le fichier source.',
            ]);
            Log::warning('ProcessLlmChunkingJob skipped: empty document', [
                'document_id' => $this->document->id,
            ]);
            return;
        }

        $wordCount = str_word_count($text);
        $minWords = 20; // Minimum de mots pour justifier un appel LLM

        if ($wordCount < $minWords) {
            $this->document->update([
                'extraction_status' => 'failed',
                'extraction_error' => "Document trop court : {$wordCount} mots (minimum {$minWords}). Le chunking LLM n'est pas adapté pour ce contenu.",
            ]);
            Log::warning('ProcessLlmChunkingJob skipped: document too short', [
                'document_id' => $this->document->id,
                'word_count' => $wordCount,
            ]);
            return;
        }

        // Marquer le document comme en cours de chunking
        $this->document->update([
            'extraction_status' => 'chunking',
            'extraction_error' => null,
        ]);

        try {
            $result = $service->processDocument($this->document);

            // Vérifier s'il y a eu des erreurs partielles
            if (!empty($result['errors'])) {
                $errorMessages = collect($result['errors'])
                    ->map(fn ($e) => "Fenêtre {$e['window_index']}: {$e['error']}")
                    ->implode("\n");

                Log::warning('LLM Chunking completed with errors', [
                    'document_id' => $this->document->id,
                    'errors' => $result['errors'],
                ]);

                // Si tous les chunks ont échoué, c'est une erreur
                if ($result['chunk_count'] === 0) {
                    throw new \RuntimeException("Aucun chunk créé. Erreurs:\n{$errorMessages}");
                }
            }

            // Marquer comme terminé
            $this->document->update([
                'extraction_status' => 'completed',
                'extracted_at' => now(),
            ]);

            Log::info('ProcessLlmChunkingJob completed', [
                'document_id' => $this->document->id,
                'chunk_count' => $result['chunk_count'],
                'window_count' => $result['window_count'],
            ]);

            // Dispatcher l'indexation Qdrant si demandé
            if ($this->reindex) {
                IndexDocumentChunksJob::dispatch($this->document);
            }

        } catch (\Exception $e) {
            Log::error('ProcessLlmChunkingJob failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Déterminer le type d'erreur
            $status = 'error';
            if (str_contains($e->getMessage(), 'JSON')) {
                $status = 'chunk_error';
            }

            $this->document->update([
                'extraction_status' => $status,
                'extraction_error' => $e->getMessage(),
            ]);

            // Ne pas relancer le job, laisser l'admin décider
            $this->fail($e);
        }
    }

    /**
     * Gestion de l'échec du job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessLlmChunkingJob failed permanently', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        $this->document->update([
            'extraction_status' => 'chunk_error',
            'extraction_error' => 'Échec du chunking LLM: ' . $exception->getMessage(),
        ]);
    }

    /**
     * Tags pour le monitoring
     */
    public function tags(): array
    {
        return [
            'llm-chunking',
            'document:' . $this->document->id,
            'agent:' . ($this->document->agent_id ?? 'none'),
        ];
    }

    /**
     * Nom d'affichage du job
     */
    public function displayName(): string
    {
        $title = $this->document->title ?? $this->document->original_name;
        return "LLM Chunking: {$title}";
    }
}
