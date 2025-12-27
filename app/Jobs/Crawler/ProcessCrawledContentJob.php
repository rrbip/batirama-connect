<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\WebCrawl;
use App\Models\WebCrawlUrlCrawl;
use App\Services\Crawler\WebCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessCrawledContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public WebCrawl $crawl,
        public WebCrawlUrlCrawl $urlEntry
    ) {
        $this->onQueue('default');
    }

    public function handle(WebCrawlerService $crawlerService): void
    {
        // Rafraîchir les modèles
        $this->crawl->refresh();
        $this->urlEntry->refresh();

        $crawlUrl = $this->urlEntry->url;
        if (!$crawlUrl) {
            Log::error('URL entry has no associated URL', ['entry_id' => $this->urlEntry->id]);
            return;
        }

        $url = $crawlUrl->url;
        $storagePath = $crawlUrl->storage_path;

        if (!$storagePath) {
            Log::error('No storage path for URL', ['url' => $url]);
            $this->markError('No content stored');
            return;
        }

        Log::info('Processing crawled content', [
            'crawl_id' => $this->crawl->id,
            'url' => $url,
            'storage_path' => $storagePath,
        ]);

        try {
            $agent = $this->crawl->agent;
            if (!$agent) {
                throw new \RuntimeException('Crawl has no associated agent');
            }

            // Déterminer le type de document
            $contentType = $crawlUrl->content_type ?? 'text/html';
            $documentType = $crawlerService->getDocumentType($contentType);

            // Déterminer la méthode d'extraction
            // Pour les images, toujours OCR
            $extractionMethod = $crawlerService->isImage($contentType)
                ? 'ocr'
                : $agent->getDefaultExtractionMethod();

            // Déterminer la stratégie de chunking
            $chunkStrategy = $agent->getDefaultChunkStrategy();

            // Générer le titre depuis l'URL
            $title = $this->generateTitleFromUrl($url);

            // Vérifier si un document existe déjà pour cette URL et cet agent
            $existingDocument = Document::where('crawl_url_id', $crawlUrl->id)
                ->where('agent_id', $agent->id)
                ->first();

            if ($existingDocument) {
                // Mettre à jour le document existant
                Log::info('Updating existing document', [
                    'document_id' => $existingDocument->id,
                    'url' => $url,
                ]);

                $existingDocument->update([
                    'storage_path' => $storagePath,
                    'extraction_status' => 'pending',
                    'extraction_method' => $extractionMethod,
                    'chunk_strategy' => $chunkStrategy,
                    'file_size' => $crawlUrl->content_length ?? 0,
                    'file_hash' => $crawlUrl->content_hash,
                ]);

                // Re-traiter le document
                ProcessDocumentJob::dispatch($existingDocument, reindex: true);

                $this->urlEntry->update([
                    'status' => 'indexed',
                    'document_id' => $existingDocument->id,
                    'indexed_at' => now(),
                ]);

            } else {
                // Créer un nouveau document
                $document = Document::create([
                    'uuid' => (string) Str::uuid(),
                    'agent_id' => $agent->id,
                    'title' => $title,
                    'storage_path' => $storagePath,
                    'original_name' => basename($storagePath),
                    'source_url' => $url,
                    'document_type' => $documentType,
                    'mime_type' => $contentType,
                    'file_size' => $crawlUrl->content_length ?? 0,
                    'file_hash' => $crawlUrl->content_hash,
                    'extraction_method' => $extractionMethod,
                    'chunk_strategy' => $chunkStrategy,
                    'extraction_status' => 'pending',
                    'is_indexed' => false,
                    'web_crawl_id' => $this->crawl->id,
                    'crawl_url_id' => $crawlUrl->id,
                ]);

                Log::info('Document created from crawl', [
                    'document_id' => $document->id,
                    'url' => $url,
                    'type' => $documentType,
                ]);

                // Dispatcher le traitement du document
                ProcessDocumentJob::dispatch($document);

                $this->urlEntry->update([
                    'status' => 'indexed',
                    'document_id' => $document->id,
                    'indexed_at' => now(),
                ]);
            }

            // Mettre à jour les stats
            $this->crawl->increment('pages_indexed');

            if ($crawlerService->isImage($contentType)) {
                $this->crawl->increment('images_found');
            } elseif ($documentType !== 'html') {
                $this->crawl->increment('documents_found');
            }

            // Mettre à jour la taille totale
            $this->crawl->increment('total_size_bytes', $crawlUrl->content_length ?? 0);

            // Propager le contenu aux autres agents utilisant cette URL
            $this->propagateToOtherAgents($crawlUrl, $agent->id, $documentType, $contentType);

            // Vérifier si le crawl est terminé
            $this->checkCrawlCompletion();

        } catch (\Exception $e) {
            Log::error('Error processing crawled content', [
                'crawl_id' => $this->crawl->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            $this->markError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Propage le contenu mis à jour aux autres agents utilisant cette URL
     */
    private function propagateToOtherAgents(
        \App\Models\WebCrawlUrl $crawlUrl,
        int $currentAgentId,
        string $documentType,
        string $contentType
    ): void {
        // Trouver tous les documents d'autres agents liés à cette URL
        $otherDocuments = Document::where('crawl_url_id', $crawlUrl->id)
            ->where('agent_id', '!=', $currentAgentId)
            ->get();

        foreach ($otherDocuments as $document) {
            Log::info('Propagating content update to other agent', [
                'document_id' => $document->id,
                'agent_id' => $document->agent_id,
                'url' => $crawlUrl->url,
            ]);

            // Déterminer la méthode d'extraction pour cet agent
            $agent = $document->agent;
            $extractionMethod = str_starts_with($contentType, 'image/')
                ? 'ocr'
                : ($agent?->getDefaultExtractionMethod() ?? 'auto');

            // Mettre à jour le document
            $document->update([
                'storage_path' => $crawlUrl->storage_path,
                'extraction_status' => 'pending',
                'extraction_method' => $extractionMethod,
                'file_size' => $crawlUrl->content_length ?? 0,
                'file_hash' => $crawlUrl->content_hash,
            ]);

            // Re-traiter le document
            ProcessDocumentJob::dispatch($document, reindex: true);
        }
    }

    /**
     * Génère un titre à partir de l'URL
     */
    private function generateTitleFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';

        // Prendre le dernier segment du chemin
        $segments = array_filter(explode('/', $path));
        $lastSegment = end($segments) ?: 'Homepage';

        // Nettoyer le segment
        $title = pathinfo($lastSegment, PATHINFO_FILENAME);
        $title = str_replace(['-', '_'], ' ', $title);
        $title = urldecode($title);
        $title = mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');

        return $title ?: 'Page Web';
    }

    /**
     * Marque l'URL comme en erreur
     */
    private function markError(string $message): void
    {
        $this->urlEntry->update([
            'status' => 'error',
            'error_message' => $message,
        ]);
        $this->crawl->increment('pages_error');
    }

    /**
     * Vérifie si le crawl est terminé
     */
    private function checkCrawlCompletion(): void
    {
        $pendingCount = WebCrawlUrlCrawl::where('crawl_id', $this->crawl->id)
            ->whereIn('status', ['pending', 'fetching', 'fetched'])
            ->count();

        if ($pendingCount === 0) {
            $this->crawl->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('Web crawl completed', [
                'crawl_id' => $this->crawl->id,
                'pages_indexed' => $this->crawl->pages_indexed,
                'pages_skipped' => $this->crawl->pages_skipped,
                'pages_error' => $this->crawl->pages_error,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessCrawledContentJob failed', [
            'crawl_id' => $this->crawl->id,
            'url_entry_id' => $this->urlEntry->id,
            'error' => $exception->getMessage(),
        ]);

        $this->markError('Processing failed: ' . $exception->getMessage());
    }
}
