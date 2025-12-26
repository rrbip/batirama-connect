<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Models\AgentWebCrawl;
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

/**
 * Job de crawl d'une URL.
 *
 * Ce job se charge uniquement du téléchargement et du stockage en cache.
 * L'indexation est déléguée à IndexAgentUrlJob pour chaque agent lié.
 */
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
        if (! in_array($this->crawl->status, ['running', 'pending'])) {
            Log::info('Crawl is no longer active, skipping URL', [
                'crawl_id' => $this->crawl->id,
                'status' => $this->crawl->status,
            ]);

            return;
        }

        // Récupérer l'URL
        $crawlUrl = $this->urlEntry->url;
        if (! $crawlUrl) {
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
            if (! $crawler->isAllowedByRobots($url, $this->crawl)) {
                $this->markError('robots_txt');
                $this->checkCrawlCompletion();

                return;
            }

            // Vérifier les domaines autorisés
            if (! $urlNormalizer->isAllowedDomain($url, $allowedDomains)) {
                $this->markError('domain_not_allowed');
                $this->checkCrawlCompletion();

                return;
            }

            // Attendre le délai de politesse
            $delay = $crawler->getCrawlDelay($this->crawl);
            if ($delay > 0) {
                usleep($delay * 1000);
            }

            // Récupérer le contenu
            $result = $crawler->fetch($url, $this->crawl);

            if (! $result['success']) {
                $this->markError($result['error'] ?? 'Unknown fetch error');
                $this->checkCrawlCompletion();

                return;
            }

            // Contenu non modifié (304) - garder le contenu existant
            if ($result['not_modified']) {
                $crawlUrl->update(['http_status' => 304]);

                $this->urlEntry->update([
                    'status' => 'fetched',
                    'fetched_at' => now(),
                ]);

                $this->crawl->increment('pages_crawled');

                // Pas besoin de réindexer si le contenu n'a pas changé
                $this->checkCrawlCompletion();

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
                $this->checkCrawlCompletion();

                return;
            }

            // Gérer les redirections - suivre automatiquement
            if ($result['status'] >= 300 && $result['status'] < 400) {
                $redirectUrl = $result['headers']['Location'][0] ?? $result['headers']['location'][0] ?? null;
                if ($redirectUrl) {
                    Log::info('Following redirect', [
                        'crawl_id' => $this->crawl->id,
                        'from' => $url,
                        'to' => $redirectUrl,
                    ]);

                    // Résoudre l'URL relative
                    if (!str_starts_with($redirectUrl, 'http')) {
                        $redirectUrl = $urlNormalizer->resolve($redirectUrl, $url);
                    }

                    // Ajouter l'URL de redirection au crawl si pas déjà présente
                    $redirectHash = $urlNormalizer->hash($redirectUrl);
                    $redirectCrawlUrl = WebCrawlUrl::firstOrCreate(
                        ['url_hash' => $redirectHash],
                        ['url' => $urlNormalizer->normalize($redirectUrl)]
                    );

                    $existingEntry = WebCrawlUrlCrawl::where('crawl_id', $this->crawl->id)
                        ->where('crawl_url_id', $redirectCrawlUrl->id)
                        ->first();

                    if (!$existingEntry) {
                        $newEntry = WebCrawlUrlCrawl::create([
                            'crawl_id' => $this->crawl->id,
                            'crawl_url_id' => $redirectCrawlUrl->id,
                            'parent_id' => $this->urlEntry->id,
                            'depth' => $this->urlEntry->depth,
                            'status' => 'pending',
                        ]);

                        $this->crawl->increment('pages_discovered');

                        CrawlUrlJob::dispatch($this->crawl, $newEntry)
                            ->delay(now()->addMilliseconds($crawler->getCrawlDelay($this->crawl)));
                    }
                }

                // Marquer cette URL comme redirection (pas une erreur)
                $this->urlEntry->update([
                    'status' => 'fetched',
                    'fetched_at' => now(),
                    'error_message' => 'redirect:' . ($redirectUrl ?? 'unknown'),
                ]);
                $this->crawl->increment('pages_crawled');
                $this->checkCrawlCompletion();

                return;
            }

            // Vérifier le type de contenu
            $contentType = $result['content_type'] ?? '';
            if (! $crawler->isSupportedContentType($contentType)) {
                $this->markError('unsupported_type');
                $this->checkCrawlCompletion();

                return;
            }

            // Vérifier la taille
            $maxSize = 10 * 1024 * 1024; // 10 Mo
            if ($result['content_length'] > $maxSize) {
                $this->markError('content_too_large');
                $this->checkCrawlCompletion();

                return;
            }

            // Si c'est du HTML, extraire les liens
            if (str_contains($contentType, 'text/html')) {
                $this->processHtmlPage($result['body'], $url, $crawler, $urlNormalizer, $allowedDomains);
            }

            // Stocker le contenu en cache (toujours, indépendamment des patterns)
            $storagePath = $crawler->storeContent(
                $result['body'],
                $contentType,
                $url
            );

            // Mettre à jour l'URL avec le contenu
            $oldContentHash = $crawlUrl->content_hash;
            $newContentHash = hash('sha256', $result['body']);
            $contentChanged = $oldContentHash !== $newContentHash;

            $crawlUrl->update([
                'storage_path' => $storagePath,
                'content_hash' => $newContentHash,
            ]);

            // Marquer comme récupéré
            $this->urlEntry->update([
                'status' => 'fetched',
                'fetched_at' => now(),
            ]);
            $this->crawl->increment('pages_crawled');

            // Déclencher l'indexation pour chaque agent lié
            if ($contentChanged || ! $oldContentHash) {
                $this->triggerAgentIndexation($crawlUrl);
            }

            $this->checkCrawlCompletion();

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
     * Déclenche l'indexation pour chaque agent lié à ce crawl.
     */
    private function triggerAgentIndexation(WebCrawlUrl $crawlUrl): void
    {
        // Récupérer tous les agents liés à ce crawl
        $agentConfigs = AgentWebCrawl::where('web_crawl_id', $this->crawl->id)->get();

        foreach ($agentConfigs as $agentConfig) {
            IndexAgentUrlJob::dispatch($agentConfig, $crawlUrl);
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
        // Vérifier les limites (0 = illimité)
        if ($this->crawl->max_pages > 0 && $this->crawl->pages_discovered >= $this->crawl->max_pages) {
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

        Log::info('Links extracted from page', [
            'crawl_id' => $this->crawl->id,
            'url' => $baseUrl,
            'depth' => $this->urlEntry->depth,
            'links_found' => count($links),
            'pages_discovered' => $this->crawl->pages_discovered,
        ]);

        $addedCount = 0;
        $filteredCount = 0;
        $existingCount = 0;

        foreach ($links as $link) {
            // Vérifier si on a atteint la limite (0 = illimité)
            if ($this->crawl->max_pages > 0 && $this->crawl->pages_discovered >= $this->crawl->max_pages) {
                break;
            }

            // Vérifier le domaine
            if (! $urlNormalizer->isAllowedDomain($link, $allowedDomains)) {
                $filteredCount++;
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
                $existingCount++;
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
            $addedCount++;

            // Dispatcher le job de crawl pour cette URL
            CrawlUrlJob::dispatch($this->crawl, $newEntry)
                ->delay(now()->addMilliseconds($crawler->getCrawlDelay($this->crawl)));
        }

        if (count($links) > 0) {
            Log::info('Links processing summary', [
                'crawl_id' => $this->crawl->id,
                'url' => $baseUrl,
                'added' => $addedCount,
                'filtered_domain' => $filteredCount,
                'already_exists' => $existingCount,
            ]);
        }
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
     * Vérifie si le crawl est terminé.
     *
     * Un crawl est terminé quand il n'y a plus d'URLs en attente (pending)
     * ni en cours de traitement (fetching).
     */
    private function checkCrawlCompletion(): void
    {
        // Rafraîchir le crawl pour avoir les stats à jour
        $this->crawl->refresh();

        // Ne pas compléter si le crawl n'est pas en cours
        if ($this->crawl->status !== 'running') {
            return;
        }

        // Compter les URLs en attente ou en cours de traitement
        // Les jobs en cours ont leur entrée marquée 'fetching' au démarrage
        // Les jobs en queue ont leur entrée marquée 'pending'
        $activeCount = WebCrawlUrlCrawl::where('crawl_id', $this->crawl->id)
            ->whereIn('status', ['pending', 'fetching'])
            ->count();

        Log::debug('Checking crawl completion', [
            'crawl_id' => $this->crawl->id,
            'active_entries' => $activeCount,
            'pages_discovered' => $this->crawl->pages_discovered,
            'pages_crawled' => $this->crawl->pages_crawled,
        ]);

        if ($activeCount === 0) {
            $this->crawl->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('Web crawl completed', [
                'crawl_id' => $this->crawl->id,
                'pages_discovered' => $this->crawl->pages_discovered,
                'pages_crawled' => $this->crawl->pages_crawled,
            ]);
        }
    }
}
