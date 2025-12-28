<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use App\Services\Marketplace\LanguageDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to detect and set locale for all products in a catalog.
 * Processes products in batches to avoid memory issues.
 */
class DetectProductLocalesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 1800; // 30 minutes

    /**
     * The catalog to process.
     */
    public FabricantCatalog $catalog;

    /**
     * Whether to overwrite existing locales.
     */
    public bool $overwrite;

    /**
     * Create a new job instance.
     */
    public function __construct(FabricantCatalog $catalog, bool $overwrite = false)
    {
        $this->catalog = $catalog;
        $this->overwrite = $overwrite;
    }

    /**
     * Execute the job.
     */
    public function handle(LanguageDetector $detector): void
    {
        Log::info('DetectProductLocalesJob: Starting locale detection', [
            'catalog_id' => $this->catalog->id,
            'catalog_name' => $this->catalog->name,
            'overwrite' => $this->overwrite,
        ]);

        $config = $this->catalog->extraction_config['locale_detection'] ?? [];

        // Check if detection is enabled
        if (isset($config['enabled']) && !$config['enabled']) {
            Log::info('DetectProductLocalesJob: Locale detection is disabled for this catalog');
            return;
        }

        $query = FabricantProduct::where('catalog_id', $this->catalog->id);

        // Only process products without locale unless overwrite is enabled
        if (!$this->overwrite) {
            $query->whereNull('locale');
        }

        $totalProducts = $query->count();
        $detected = 0;
        $failed = 0;

        Log::info("DetectProductLocalesJob: Processing {$totalProducts} products");

        // Process in chunks of 100
        $query->chunkById(100, function ($products) use ($detector, $config, &$detected, &$failed) {
            foreach ($products as $product) {
                try {
                    // Build content from multiple sources
                    $content = $this->buildContentForDetection($product);

                    $locale = $detector->detect(
                        $product->source_url,
                        $product->sku,
                        $content,
                        $config
                    );

                    if ($locale) {
                        $product->update(['locale' => $locale]);
                        $detected++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('DetectProductLocalesJob: Failed to detect locale for product', [
                        'product_id' => $product->id,
                        'error' => $e->getMessage(),
                    ]);
                    $failed++;
                }
            }
        });

        Log::info('DetectProductLocalesJob: Locale detection completed', [
            'catalog_id' => $this->catalog->id,
            'total_processed' => $totalProducts,
            'detected' => $detected,
            'failed' => $failed,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('DetectProductLocalesJob: Job failed', [
            'catalog_id' => $this->catalog->id,
            'error' => $exception->getMessage(),
        ]);
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
            'locale-detection',
        ];
    }

    /**
     * Build content string from multiple product sources for better language detection.
     */
    private function buildContentForDetection(FabricantProduct $product): string
    {
        // PRIORITY 1: Try to get raw HTML content to detect lang attribute
        // This is the most reliable method
        if ($product->crawl_url_id) {
            try {
                $crawlUrl = $product->crawlUrl;
                if ($crawlUrl) {
                    Log::debug('DetectProductLocalesJob: Found crawlUrl', [
                        'product_id' => $product->id,
                        'crawl_url_id' => $product->crawl_url_id,
                        'storage_path' => $crawlUrl->storage_path,
                    ]);

                    $rawHtml = $crawlUrl->getContent();
                    if ($rawHtml) {
                        // Return first 2000 chars of raw HTML (enough to capture <html lang="...">)
                        $content = mb_substr($rawHtml, 0, 2000);
                        Log::debug('DetectProductLocalesJob: Got raw HTML', [
                            'product_id' => $product->id,
                            'html_length' => strlen($rawHtml),
                            'content_preview' => mb_substr($content, 0, 300),
                        ]);
                        return $content;
                    } else {
                        Log::debug('DetectProductLocalesJob: getContent() returned empty', [
                            'product_id' => $product->id,
                            'storage_path' => $crawlUrl->storage_path,
                        ]);
                    }
                } else {
                    Log::debug('DetectProductLocalesJob: crawlUrl relationship is null', [
                        'product_id' => $product->id,
                        'crawl_url_id' => $product->crawl_url_id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::debug('DetectProductLocalesJob: Could not read raw HTML content', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::debug('DetectProductLocalesJob: Product has no crawl_url_id', [
                'product_id' => $product->id,
            ]);
        }

        // PRIORITY 2: Fall back to product text content
        $parts = [];

        // 1. Product name (always available)
        if (!empty($product->name)) {
            $parts[] = $product->name;
        }

        // 2. Description
        if (!empty($product->description)) {
            $parts[] = $product->description;
        }

        // 3. Short description
        if (!empty($product->short_description)) {
            $parts[] = $product->short_description;
        }

        // 4. Category
        if (!empty($product->category)) {
            $parts[] = $product->category;
        }

        // 5. Specifications (if array, extract text values)
        if (!empty($product->specifications) && is_array($product->specifications)) {
            foreach ($product->specifications as $key => $value) {
                if (is_string($value)) {
                    $parts[] = $value;
                } elseif (is_array($value)) {
                    $parts[] = implode(' ', array_filter($value, 'is_string'));
                }
            }
        }

        return implode(' ', $parts);
    }
}
