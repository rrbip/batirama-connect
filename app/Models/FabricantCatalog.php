<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Fabricant Catalog - Links fabricants to web crawls for product extraction.
 *
 * @property int $id
 * @property string $uuid
 * @property int $fabricant_id
 * @property int|null $web_crawl_id
 * @property string $name
 * @property string|null $description
 * @property string $website_url
 * @property array|null $extraction_config
 * @property string $status
 * @property int $products_found
 * @property int $products_updated
 * @property int $products_failed
 * @property \Carbon\Carbon|null $last_crawl_at
 * @property \Carbon\Carbon|null $last_extraction_at
 * @property string|null $last_error
 * @property string|null $refresh_frequency
 * @property \Carbon\Carbon|null $next_refresh_at
 */
class FabricantCatalog extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_CRAWLING = 'crawling';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Refresh frequency constants
    public const REFRESH_DAILY = 'daily';
    public const REFRESH_WEEKLY = 'weekly';
    public const REFRESH_MONTHLY = 'monthly';
    public const REFRESH_MANUAL = 'manual';

    protected $fillable = [
        'uuid',
        'fabricant_id',
        'web_crawl_id',
        'name',
        'description',
        'website_url',
        'extraction_config',
        'status',
        'products_found',
        'products_updated',
        'products_failed',
        'last_crawl_at',
        'last_extraction_at',
        'last_error',
        'refresh_frequency',
        'next_refresh_at',
    ];

    protected function casts(): array
    {
        return [
            'extraction_config' => 'array',
            'last_crawl_at' => 'datetime',
            'last_extraction_at' => 'datetime',
            'next_refresh_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (FabricantCatalog $catalog) {
            if (empty($catalog->uuid)) {
                $catalog->uuid = (string) Str::uuid();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    /**
     * The fabricant who owns this catalog.
     */
    public function fabricant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fabricant_id');
    }

    /**
     * The web crawl used to populate this catalog.
     */
    public function webCrawl(): BelongsTo
    {
        return $this->belongsTo(WebCrawl::class);
    }

    /**
     * Products in this catalog.
     */
    public function products(): HasMany
    {
        return $this->hasMany(FabricantProduct::class, 'catalog_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_EXTRACTING]);
    }

    public function scopeNeedsRefresh($query)
    {
        return $query->where('next_refresh_at', '<=', now())
            ->whereNotIn('status', [self::STATUS_CRAWLING, self::STATUS_EXTRACTING]);
    }

    public function scopeForFabricant($query, int $fabricantId)
    {
        return $query->where('fabricant_id', $fabricantId);
    }

    // ─────────────────────────────────────────────────────────────────
    // METHODS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get default extraction config with selectors for common patterns.
     */
    public static function getDefaultExtractionConfig(): array
    {
        return [
            'product_url_patterns' => [
                '*/produit/*',
                '*/fiche-technique/*',
                '*/product/*',
                '*/article/*',
            ],
            'use_llm_extraction' => true,
            'selectors' => [
                'name' => 'h1, .product-title, .product-name',
                'price' => '.price, .product-price, [itemprop="price"]',
                'sku' => '.sku, .reference, [itemprop="sku"]',
                'description' => '.description, .product-description, [itemprop="description"]',
                'image' => '.product-image img, .gallery img, [itemprop="image"]',
                'specs' => '.specifications, .technical-specs, .caractéristiques',
            ],
            // Locale detection configuration
            'locale_detection' => [
                'enabled' => true,
                'methods' => [
                    'url' => true,      // Detect from URL patterns (/fr/, /en/, etc.)
                    'sku' => true,      // Detect from SKU patterns (-FR, -EN, etc.)
                    'content' => true,  // Detect from content analysis (common words)
                ],
                'allowed_locales' => ['fr', 'en', 'de', 'es', 'it', 'nl', 'pt', 'pl'],
                'default_locale' => null, // Force a specific locale for all products
            ],
        ];
    }

    /**
     * Get supported locales for display.
     */
    public static function getSupportedLocales(): array
    {
        return [
            'fr' => 'Français',
            'en' => 'English',
            'de' => 'Deutsch',
            'es' => 'Español',
            'it' => 'Italiano',
            'nl' => 'Nederlands',
            'pt' => 'Português',
            'pl' => 'Polski',
        ];
    }

    /**
     * Check if the catalog is currently processing.
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, [self::STATUS_CRAWLING, self::STATUS_EXTRACTING]);
    }

    /**
     * Check if the catalog needs a refresh.
     */
    public function needsRefresh(): bool
    {
        if ($this->refresh_frequency === self::REFRESH_MANUAL) {
            return false;
        }

        if ($this->next_refresh_at === null) {
            return true;
        }

        return $this->next_refresh_at->isPast();
    }

    /**
     * Calculate next refresh date based on frequency.
     */
    public function calculateNextRefresh(): ?\Carbon\Carbon
    {
        return match ($this->refresh_frequency) {
            self::REFRESH_DAILY => now()->addDay(),
            self::REFRESH_WEEKLY => now()->addWeek(),
            self::REFRESH_MONTHLY => now()->addMonth(),
            default => null,
        };
    }

    /**
     * Mark catalog as crawling.
     */
    public function markAsCrawling(): void
    {
        $this->update([
            'status' => self::STATUS_CRAWLING,
            'last_crawl_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Mark catalog as extracting products.
     */
    public function markAsExtracting(): void
    {
        $this->update([
            'status' => self::STATUS_EXTRACTING,
            'last_extraction_at' => now(),
        ]);
    }

    /**
     * Mark catalog as completed.
     */
    public function markAsCompleted(int $found = 0, int $updated = 0, int $failed = 0): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'products_found' => $found,
            'products_updated' => $updated,
            'products_failed' => $failed,
            'next_refresh_at' => $this->calculateNextRefresh(),
        ]);
    }

    /**
     * Mark catalog as failed.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'last_error' => $error,
        ]);
    }

    /**
     * Get statistics about this catalog.
     */
    public function getStats(): array
    {
        return [
            'total_products' => $this->products()->count(),
            'active_products' => $this->products()->where('status', FabricantProduct::STATUS_ACTIVE)->count(),
            'pending_review' => $this->products()->where('status', FabricantProduct::STATUS_PENDING_REVIEW)->count(),
            'verified_products' => $this->products()->where('is_verified', true)->count(),
            'with_price' => $this->products()->whereNotNull('price_ht')->count(),
            'with_images' => $this->products()->whereNotNull('main_image_url')->count(),
        ];
    }

    /**
     * Convert to API response format.
     */
    public function toApiResponse(): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'website_url' => $this->website_url,
            'status' => $this->status,
            'products_count' => $this->products()->count(),
            'last_crawl_at' => $this->last_crawl_at?->toIso8601String(),
            'last_extraction_at' => $this->last_extraction_at?->toIso8601String(),
            'next_refresh_at' => $this->next_refresh_at?->toIso8601String(),
            'stats' => $this->getStats(),
        ];
    }
}
