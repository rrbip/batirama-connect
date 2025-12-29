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
        private WebCrawl $crawl,
        private bool $forceAll = false
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Get the PDF extraction method from crawl settings
        $pdfExtractionMethod = $this->crawl->pdf_extraction_method ?? 'auto';

        Log::info('DetectCrawlUrlLocalesJob: Starting locale detection', [
            'crawl_id' => $this->crawl->id,
            'pdf_extraction_method' => $pdfExtractionMethod,
            'force_all' => $this->forceAll,
        ]);

        $detected = 0;
        $failed = 0;

        // Get URLs for this crawl (with or without existing locale based on forceAll)
        $query = WebCrawlUrl::query()
            ->whereHas('crawls', fn ($q) => $q->where('web_crawls.id', $this->crawl->id))
            ->whereNotNull('storage_path');

        if (!$this->forceAll) {
            $query->whereNull('locale');
        }

        $urls = $query->cursor(); // Use cursor for memory efficiency

        foreach ($urls as $url) {
            try {
                // Reset locale if forcing re-detection
                if ($this->forceAll && $url->locale !== null) {
                    $url->update(['locale' => null]);
                }

                $locale = $url->detectAndSaveLocale($pdfExtractionMethod);
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
            'force_all' => $this->forceAll,
            'detected' => $detected,
            'failed' => $failed,
        ]);
    }
}
