<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Models\WebCrawl;
use App\Models\WebCrawlUrl;
use App\Models\WebCrawlUrlCrawl;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Crawler\WebCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CrawlUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 120;

    public function __construct(
        public WebCrawl $crawl,
        public WebCrawlUrlCrawl $urlEntry
    ) {
        $this->onQueue('default');
    }

    public function handle(WebCrawlerService $crawler, UrlNormalizer $urlNormalizer): void
    {
        // Rafraîchir les modèles
        $this->crawl->refresh();
        $this->urlEntry->refresh();

        // Vérifier que le crawl est toujours actif
        if (!in_array($this->crawl->status, ['running', 'pending'])) {
            Log::info('Crawl is no longer active, skipping URL', [
                'crawl_id' => $this->crawl->id,
                'status' => $this->crawl->status,
            ]);
            return;
        }

        // Récupérer l'URL
        $crawlUrl = $this->urlEntry->url;
        if (!$crawlUrl) {
            Log::error('URL entry has no associated URL', ['entry_id' => $this->urlEntry->id]);
            return;
        }

        $url = $crawlUrl->url;

        // Déterminer les domaines autorisés (utiliser start_url si non défini)
        $allowedDomains = $this->crawl->allowed_domains ?? [];
        if (empty($allowedDomains)) {
            $startDomain = $urlNormalizer->getDomain($this->crawl->start_url);
            if ($startDomain) {
                $allowedDomains = [$startDomain];
            }
        }

        Log::info('Crawling URL', [
            'crawl_id' => $this->crawl->id,
            'url' => $url,
            'depth' => $this->urlEntry->depth,
        ]);

        // Marquer comme en cours
        $this->urlEntry->update(['status' => 'fetching']);

        try {
            // Vérifier robots.txt
            if (!$crawler->isAllowedByRobots($url, $this->crawl)) {
                $this->markSkipped('robots_txt');
                return;
            }

            // Vérifier les domaines autorisés
            if (!$urlNormalizer->isAllowedDomain($url, $allowedDomains)) {
                $this->markSkipped('domain_not_allowed');
                return;
            }

            // Attendre le délai de politesse
            $delay = $crawler->getCrawlDelay($this->crawl);
            if ($delay > 0) {
                usleep($delay * 1000);
            }

            // Récupérer le contenu
            $result = $crawler->fetch($url, $this->crawl);

            if (!$result['success']) {
                $this->markError($result['error'] ?? 'Unknown fetch error');
                return;
            }

            // Contenu non modifié (304) - garder le contenu existant
            if ($result['not_modified']) {
                // Le contenu n'a pas changé, on garde l'ancien cache
                // Mettre à jour le statut HTTP mais garder le storage_path existant
                $crawlUrl->update(['http_status' => 304]);

                // Si le contenu était déjà indexé, marquer comme indexé
                // Sinon comme fetched pour que le bouton "Voir" fonctionne
                $newStatus = $crawlUrl->storage_path ? 'indexed' : 'skipped';
                $this->urlEntry->update([
                    'status' => $newStatus,
                    'skip_reason' => 'not_modified',
                    'fetched_at' => now(),
                ]);

                $this->crawl->increment('pages_crawled');
                return;
            }

            // Mettre à jour les infos de l'URL
            $crawlUrl->update([
                'http_status' => $result['status'],
                'content_type' => $result['content_type'],
                'content_length' => $result['content_length'],
                'etag' => $result['etag'],
                'last_modified' => $result['last_modified'],
            ]);

            // Vérifier le code HTTP
            if ($result['status'] >= 400) {
                $this->markError("HTTP {$result['status']}");
                return;
            }

            // Gérer les redirections
            if ($result['status'] >= 300 && $result['status'] < 400) {
                // TODO: Suivre la redirection
                $this->markSkipped('redirect');
                return;
            }

            // Vérifier le type de contenu
            $contentType = $result['content_type'] ?? '';
            if (!$crawler->isSupportedContentType($contentType)) {
                $this->markSkipped('unsupported_type');
                return;
            }

            // Vérifier la taille
            $maxSize = 10 * 1024 * 1024; // 10 Mo
            if ($result['content_length'] > $maxSize) {
                $this->markSkipped('content_too_large');
                return;
            }

            // Marquer comme récupéré
            $this->urlEntry->update([
                'status' => 'fetched',
                'fetched_at' => now(),
            ]);
            $this->crawl->increment('pages_crawled');

            // Si c'est du HTML, extraire les liens
            if (str_contains($contentType, 'text/html')) {
                $this->processHtmlPage($result['body'], $url, $crawler, $urlNormalizer, $allowedDomains);
            }

            // Vérifier si doit indexer
            $indexCheck = $crawler->shouldIndex($url, $this->crawl);

            if (!$indexCheck['should_index']) {
                $this->urlEntry->update([
                    'status' => 'skipped',
                    'skip_reason' => $indexCheck['skip_reason'],
                    'matched_pattern' => $indexCheck['matched_pattern'],
                ]);
                $this->crawl->increment('pages_skipped');
                return;
            }

            // Stocker le contenu
            $storagePath = $crawler->storeContent(
                $result['body'],
                $contentType,
                $url
            );

            // Mettre à jour l'URL avec le contenu
            $contentHash = hash('sha256', $result['body']);
            $crawlUrl->update([
                'storage_path' => $storagePath,
                'content_hash' => $contentHash,
            ]);

            // Dispatcher le job de traitement du contenu
            ProcessCrawledContentJob::dispatch($this->crawl, $this->urlEntry);

        } catch (\Exception $e) {
            Log::error('Error crawling URL', [
                'crawl_id' => $this->crawl->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            $this->markError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Traite une page HTML pour extraire les liens
     */
    private function processHtmlPage(
        string $html,
        string $baseUrl,
        WebCrawlerService $crawler,
        UrlNormalizer $urlNormalizer,
        array $allowedDomains
    ): void {
        // Vérifier les limites
        if ($this->crawl->pages_discovered >= $this->crawl->max_pages) {
            Log::info('Max pages limit reached', ['crawl_id' => $this->crawl->id]);
            return;
        }

        if ($this->urlEntry->depth >= $this->crawl->max_depth) {
            Log::debug('Max depth reached for this branch', [
                'crawl_id' => $this->crawl->id,
                'depth' => $this->urlEntry->depth,
            ]);
            return;
        }

        // Extraire les liens
        $links = $crawler->extractLinks($html, $baseUrl);

        foreach ($links as $link) {
            // Vérifier si on a atteint la limite
            if ($this->crawl->pages_discovered >= $this->crawl->max_pages) {
                break;
            }

            // Vérifier le domaine
            if (!$urlNormalizer->isAllowedDomain($link, $allowedDomains)) {
                continue;
            }

            // Normaliser et hasher
            $normalizedLink = $urlNormalizer->normalize($link);
            $linkHash = $urlNormalizer->hash($link);

            // Créer ou récupérer l'URL
            $crawlUrl = WebCrawlUrl::firstOrCreate(
                ['url_hash' => $linkHash],
                ['url' => $normalizedLink]
            );

            // Vérifier si déjà dans ce crawl
            $existingEntry = WebCrawlUrlCrawl::where('crawl_id', $this->crawl->id)
                ->where('crawl_url_id', $crawlUrl->id)
                ->first();

            if ($existingEntry) {
                continue;
            }

            // Créer l'entrée pivot
            $newEntry = WebCrawlUrlCrawl::create([
                'crawl_id' => $this->crawl->id,
                'crawl_url_id' => $crawlUrl->id,
                'parent_id' => $this->urlEntry->id,
                'depth' => $this->urlEntry->depth + 1,
                'status' => 'pending',
            ]);

            $this->crawl->increment('pages_discovered');

            // Dispatcher le job de crawl pour cette URL
            CrawlUrlJob::dispatch($this->crawl, $newEntry)
                ->delay(now()->addMilliseconds($crawler->getCrawlDelay($this->crawl)));
        }
    }

    /**
     * Marque l'URL comme skippée
     */
    private function markSkipped(string $reason): void
    {
        $this->urlEntry->update([
            'status' => 'skipped',
            'skip_reason' => $reason,
        ]);
        $this->crawl->increment('pages_skipped');

        Log::debug('URL skipped', [
            'crawl_id' => $this->crawl->id,
            'url' => $this->urlEntry->url?->url,
            'reason' => $reason,
        ]);
    }

    /**
     * Marque l'URL comme en erreur
     */
    private function markError(string $message): void
    {
        $this->urlEntry->update([
            'status' => 'error',
            'error_message' => $message,
            'retry_count' => $this->urlEntry->retry_count + 1,
        ]);
        $this->crawl->increment('pages_error');

        Log::warning('URL error', [
            'crawl_id' => $this->crawl->id,
            'url' => $this->urlEntry->url?->url,
            'error' => $message,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CrawlUrlJob failed definitively', [
            'crawl_id' => $this->crawl->id,
            'url_entry_id' => $this->urlEntry->id,
            'error' => $exception->getMessage(),
        ]);

        $this->markError('Job failed: ' . $exception->getMessage());

        // Vérifier si le crawl doit être terminé
        $this->checkCrawlCompletion();
    }

    /**
     * Vérifie si le crawl est terminé
     */
    private function checkCrawlCompletion(): void
    {
        $pendingCount = WebCrawlUrlCrawl::where('crawl_id', $this->crawl->id)
            ->whereIn('status', ['pending', 'fetching'])
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
}
