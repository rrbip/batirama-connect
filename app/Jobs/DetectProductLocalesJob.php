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
                    $locale = $detector->detect(
                        $product->source_url,
                        $product->sku,
                        $product->description ?? $product->name,
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
}
