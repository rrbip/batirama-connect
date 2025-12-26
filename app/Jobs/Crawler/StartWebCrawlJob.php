<?php

declare(strict_types=1);

namespace App\Jobs\Crawler;

use App\Models\WebCrawl;
use App\Models\WebCrawlUrl;
use App\Models\WebCrawlUrlCrawl;
use App\Services\Crawler\RobotsTxtParser;
use App\Services\Crawler\UrlNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StartWebCrawlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public WebCrawl $crawl
    ) {
        $this->onQueue('default');
    }

    public function handle(UrlNormalizer $urlNormalizer): void
    {
        Log::info('Starting web crawl', [
            'crawl_id' => $this->crawl->id,
            'start_url' => $this->crawl->start_url,
        ]);

        try {
            // Marquer comme en cours
            $this->crawl->update([
                'status' => 'running',
                'started_at' => now(),
            ]);

            // Parser robots.txt si nécessaire
            if ($this->crawl->respect_robots_txt) {
                $robotsParser = new RobotsTxtParser($this->crawl->user_agent);
                $robotsParser->load($this->getBaseUrl($this->crawl->start_url));

                // Ajouter les sitemaps découverts
                foreach ($robotsParser->getSitemaps() as $sitemap) {
                    Log::info('Sitemap discovered', ['url' => $sitemap]);
                    // TODO: Parser les sitemaps pour découvrir plus d'URLs
                }
            }

            // Normaliser l'URL de départ
            $normalizedUrl = $urlNormalizer->normalize($this->crawl->start_url);
            $urlHash = $urlNormalizer->hash($this->crawl->start_url);

            // Créer ou récupérer l'URL de départ
            $crawlUrl = WebCrawlUrl::firstOrCreate(
                ['url_hash' => $urlHash],
                ['url' => $normalizedUrl]
            );

            // Créer l'entrée pivot (ou récupérer si déjà existante - cas de retry)
            $urlEntry = WebCrawlUrlCrawl::firstOrCreate(
                [
                    'crawl_id' => $this->crawl->id,
                    'crawl_url_id' => $crawlUrl->id,
                ],
                [
                    'depth' => 0,
                    'status' => 'pending',
                ]
            );

            // Mettre à jour les stats seulement si c'est une nouvelle entrée
            if ($urlEntry->wasRecentlyCreated) {
                $this->crawl->increment('pages_discovered');
            }

            // Dispatcher le job de crawl pour l'URL de départ
            CrawlUrlJob::dispatch($this->crawl, $urlEntry);

            Log::info('Web crawl started successfully', [
                'crawl_id' => $this->crawl->id,
                'start_url_entry_id' => $urlEntry->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to start web crawl', [
                'crawl_id' => $this->crawl->id,
                'error' => $e->getMessage(),
            ]);

            $this->crawl->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    private function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('StartWebCrawlJob failed', [
            'crawl_id' => $this->crawl->id,
            'error' => $exception->getMessage(),
        ]);

        $this->crawl->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);
    }
}
