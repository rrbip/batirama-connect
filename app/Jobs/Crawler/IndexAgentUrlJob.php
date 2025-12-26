<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Jobs\ProcessDocumentJob;
use App\Models\AgentWebCrawl;
use App\Models\AgentWebCrawlUrl;
use App\Models\Document;
use App\Models\WebCrawlUrl;
use App\Services\Crawler\WebCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job d'indexation d'une URL pour un agent spécifique.
 *
 * Ce job vérifie les filtres de l'agent et crée le document RAG si applicable.
 */
class IndexAgentUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public AgentWebCrawl $agentConfig,
        public WebCrawlUrl $crawlUrl
    ) {
        // Utiliser la queue llm-chunking si la stratégie est llm_assisted
        $chunkStrategy = $agentConfig->effective_chunk_strategy;
        $this->onQueue($chunkStrategy === 'llm_assisted' ? 'llm-chunking' : 'default');
    }

    public function handle(WebCrawlerService $crawlerService): void
    {
        // Rafraîchir les modèles
        $this->agentConfig->refresh();
        $this->crawlUrl->refresh();

        $url = $this->crawlUrl->url;
        $agent = $this->agentConfig->agent;
        $webCrawl = $this->agentConfig->webCrawl;

        if (! $agent || ! $webCrawl) {
            Log::error('Invalid agent config', ['config_id' => $this->agentConfig->id]);

            return;
        }

        Log::info('Indexing URL for agent', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'url' => $url,
        ]);

        // Créer ou récupérer l'entrée AgentWebCrawlUrl
        $urlEntry = AgentWebCrawlUrl::firstOrCreate(
            [
                'agent_web_crawl_id' => $this->agentConfig->id,
                'web_crawl_url_id' => $this->crawlUrl->id,
            ],
            ['status' => 'pending']
        );

        try {
            // Vérifier si le contenu existe
            $storagePath = $this->crawlUrl->storage_path;
            if (! $storagePath) {
                $this->markSkipped($urlEntry, 'no_content');

                return;
            }

            $contentType = $this->crawlUrl->content_type ?? 'text/html';

            // Vérifier le type de contenu
            if (! $this->agentConfig->shouldIndexContentType($contentType)) {
                $this->markSkipped($urlEntry, 'content_type');

                return;
            }

            // Vérifier les patterns URL
            if (! $this->agentConfig->shouldIndexUrl($url)) {
                $matchedPattern = $this->findMatchedPattern($url);
                $skipReason = $this->agentConfig->url_filter_mode === 'exclude'
                    ? 'pattern_exclude'
                    : 'pattern_not_include';

                $this->markSkipped($urlEntry, $skipReason, $matchedPattern);

                return;
            }

            // Déterminer le type de document
            $documentType = $crawlerService->getDocumentType($contentType);

            // Déterminer la méthode d'extraction
            $extractionMethod = $crawlerService->isImage($contentType)
                ? 'ocr'
                : $agent->getDefaultExtractionMethod();

            // Déterminer la stratégie de chunking
            $chunkStrategy = $this->agentConfig->effective_chunk_strategy;

            // Générer le titre depuis l'URL
            $title = $this->generateTitleFromUrl($url);

            // Vérifier si un document existe déjà pour cette URL et cet agent
            $existingDocument = Document::where('crawl_url_id', $this->crawlUrl->id)
                ->where('agent_id', $agent->id)
                ->first();

            if ($existingDocument) {
                // Mettre à jour le document existant
                Log::info('Updating existing document for agent', [
                    'document_id' => $existingDocument->id,
                    'agent_id' => $agent->id,
                    'url' => $url,
                ]);

                $existingDocument->update([
                    'storage_path' => $storagePath,
                    'extraction_status' => 'pending',
                    'extraction_method' => $extractionMethod,
                    'chunk_strategy' => $chunkStrategy,
                    'file_size' => $this->crawlUrl->content_length ?? 0,
                    'file_hash' => $this->crawlUrl->content_hash,
                ]);

                // Re-traiter le document
                ProcessDocumentJob::dispatch($existingDocument, reindex: true);

                $urlEntry->update([
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
                    'file_size' => $this->crawlUrl->content_length ?? 0,
                    'file_hash' => $this->crawlUrl->content_hash,
                    'extraction_method' => $extractionMethod,
                    'chunk_strategy' => $chunkStrategy,
                    'extraction_status' => 'pending',
                    'is_indexed' => false,
                    'web_crawl_id' => $webCrawl->id,
                    'crawl_url_id' => $this->crawlUrl->id,
                ]);

                Log::info('Document created from crawl for agent', [
                    'document_id' => $document->id,
                    'agent_id' => $agent->id,
                    'url' => $url,
                    'type' => $documentType,
                    'chunk_strategy' => $chunkStrategy,
                ]);

                // Dispatcher le traitement du document
                ProcessDocumentJob::dispatch($document);

                $urlEntry->update([
                    'status' => 'indexed',
                    'document_id' => $document->id,
                    'indexed_at' => now(),
                ]);
            }

            // Mettre à jour les stats de l'agent config
            $this->agentConfig->increment('pages_indexed');

            // Mettre à jour la taille totale du crawl
            $webCrawl->increment('total_size_bytes', $this->crawlUrl->content_length ?? 0);

            $this->checkIndexationCompletion();

        } catch (\Exception $e) {
            Log::error('Error indexing URL for agent', [
                'agent_id' => $agent->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            $urlEntry->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
            $this->agentConfig->increment('pages_error');

            throw $e;
        }
    }

    /**
     * Marque l'URL comme skippée pour cet agent.
     */
    private function markSkipped(AgentWebCrawlUrl $urlEntry, string $reason, ?string $matchedPattern = null): void
    {
        $urlEntry->update([
            'status' => 'skipped',
            'skip_reason' => $reason,
            'matched_pattern' => $matchedPattern,
        ]);
        $this->agentConfig->increment('pages_skipped');

        Log::debug('URL skipped for agent', [
            'agent_id' => $this->agentConfig->agent_id,
            'url' => $this->crawlUrl->url,
            'reason' => $reason,
        ]);
    }

    /**
     * Trouve le pattern qui a matché pour cette URL.
     */
    private function findMatchedPattern(string $url): ?string
    {
        $patterns = $this->agentConfig->url_patterns ?? [];
        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        foreach ($patterns as $pattern) {
            if ($this->urlMatchesPattern($path, $pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Vérifie si un path correspond à un pattern.
     */
    private function urlMatchesPattern(string $path, string $pattern): bool
    {
        if (str_starts_with($pattern, '^')) {
            return (bool) preg_match('/' . $pattern . '/', $path);
        }

        $regex = str_replace(
            ['*', '/'],
            ['.*', '\/'],
            $pattern
        );

        return (bool) preg_match('/^' . $regex . '$/', $path);
    }

    /**
     * Génère un titre à partir de l'URL.
     */
    private function generateTitleFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';

        $segments = array_filter(explode('/', $path));
        $lastSegment = end($segments) ?: 'Homepage';

        $title = pathinfo($lastSegment, PATHINFO_FILENAME);
        $title = str_replace(['-', '_'], ' ', $title);
        $title = urldecode($title);
        $title = mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');

        return $title ?: 'Page Web';
    }

    /**
     * Vérifie si l'indexation est terminée pour cet agent.
     */
    private function checkIndexationCompletion(): void
    {
        $pendingCount = AgentWebCrawlUrl::where('agent_web_crawl_id', $this->agentConfig->id)
            ->where('status', 'pending')
            ->count();

        if ($pendingCount === 0) {
            $this->agentConfig->update([
                'index_status' => 'indexed',
                'last_indexed_at' => now(),
            ]);

            Log::info('Agent indexation completed', [
                'agent_id' => $this->agentConfig->agent_id,
                'web_crawl_id' => $this->agentConfig->web_crawl_id,
                'pages_indexed' => $this->agentConfig->pages_indexed,
                'pages_skipped' => $this->agentConfig->pages_skipped,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('IndexAgentUrlJob failed', [
            'agent_config_id' => $this->agentConfig->id,
            'crawl_url_id' => $this->crawlUrl->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
