<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FabricantCatalog;
use App\Services\Marketplace\ProductMetadataExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to extract product metadata from a fabricant catalog's web crawl.
 *
 * This job processes all crawled URLs from the associated WebCrawl,
 * identifies product pages, and extracts product metadata using
 * CSS selectors and/or LLM-based extraction.
 */
class ExtractFabricantProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * The catalog to extract products from.
     */
    public FabricantCatalog $catalog;

    /**
     * Create a new job instance.
     */
    public function __construct(FabricantCatalog $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * Execute the job.
     */
    public function handle(ProductMetadataExtractor $extractor): void
    {
        Log::info('ExtractFabricantProductsJob: Starting extraction', [
            'catalog_id' => $this->catalog->id,
            'catalog_name' => $this->catalog->name,
        ]);

        try {
            // Check if catalog has a web crawl
            if (!$this->catalog->webCrawl) {
                throw new \RuntimeException('No web crawl associated with catalog');
            }

            // Check if crawl is completed
            $crawl = $this->catalog->webCrawl;
            if ($crawl->status !== 'completed' && $crawl->status !== 'paused') {
                Log::warning('ExtractFabricantProductsJob: Crawl not completed, waiting...', [
                    'crawl_status' => $crawl->status,
                ]);

                // Re-queue with delay if crawl is still running
                if (in_array($crawl->status, ['pending', 'running'])) {
                    self::dispatch($this->catalog)->delay(now()->addMinutes(5));
                    return;
                }

                throw new \RuntimeException('Web crawl failed or cancelled');
            }

            // Process all URLs
            $stats = $extractor->processAllCrawlUrls($this->catalog);

            Log::info('ExtractFabricantProductsJob: Extraction completed', [
                'catalog_id' => $this->catalog->id,
                'stats' => $stats,
            ]);

        } catch (\Throwable $e) {
            Log::error('ExtractFabricantProductsJob: Extraction failed', [
                'catalog_id' => $this->catalog->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->catalog->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractFabricantProductsJob: Job failed permanently', [
            'catalog_id' => $this->catalog->id,
            'error' => $exception->getMessage(),
        ]);

        $this->catalog->markAsFailed('Extraction failed after ' . $this->tries . ' attempts: ' . $exception->getMessage());
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'catalog:' . $this->catalog->id,
            'fabricant:' . $this->catalog->fabricant_id,
        ];
    }
}
