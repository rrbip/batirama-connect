<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebCrawl;
use App\Models\WebCrawlUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job to detect locales for all URLs in a web crawl.
 */
class DetectCrawlUrlLocalesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600; // 1 hour max

    public function __construct(
        private WebCrawl $crawl
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('DetectCrawlUrlLocalesJob: Starting locale detection', [
            'crawl_id' => $this->crawl->id,
        ]);

        $detected = 0;
        $failed = 0;

        // Get all URLs without locale for this crawl
        $urls = WebCrawlUrl::query()
            ->whereHas('crawls', fn ($q) => $q->where('web_crawls.id', $this->crawl->id))
            ->whereNotNull('storage_path')
            ->whereNull('locale')
            ->cursor(); // Use cursor for memory efficiency

        foreach ($urls as $url) {
            try {
                $locale = $url->detectAndSaveLocale();
                if ($locale) {
                    $detected++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::debug('DetectCrawlUrlLocalesJob: Error detecting locale', [
                    'url_id' => $url->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        Log::info('DetectCrawlUrlLocalesJob: Completed', [
            'crawl_id' => $this->crawl->id,
            'detected' => $detected,
            'failed' => $failed,
        ]);
    }
}
