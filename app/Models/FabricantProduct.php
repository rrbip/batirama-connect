<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Fabricant Product - Product metadata extracted from crawled pages.
 *
 * @property int $id
 * @property string $uuid
 * @property int $catalog_id
 * @property int|null $crawl_url_id
 * @property string|null $sku
 * @property string|null $ean
 * @property string|null $manufacturer_ref
 * @property string $name
 * @property string|null $description
 * @property string|null $short_description
 * @property string|null $brand
 * @property string|null $category
 * @property float|null $price_ht
 * @property float|null $price_ttc
 * @property float $tva_rate
 * @property string $currency
 * @property string|null $price_unit
 * @property string|null $availability
 * @property int|null $stock_quantity
 * @property int|null $min_order_quantity
 * @property string|null $lead_time
 * @property array|null $images
 * @property string|null $main_image_url
 * @property array|null $documents
 * @property array|null $specifications
 * @property float|null $weight_kg
 * @property float|null $width_cm
 * @property float|null $height_cm
 * @property float|null $depth_cm
 * @property string|null $source_url
 * @property string|null $source_hash
 * @property string|null $extraction_method
 * @property float|null $extraction_confidence
 * @property string $status
 * @property bool $is_verified
 * @property \Carbon\Carbon|null $verified_at
 * @property bool $marketplace_visible
 * @property array|null $marketplace_metadata
 */
class FabricantProduct extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    // Status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_ARCHIVED = 'archived';

    // Availability constants
    public const AVAILABILITY_IN_STOCK = 'in_stock';
    public const AVAILABILITY_OUT_OF_STOCK = 'out_of_stock';
    public const AVAILABILITY_ON_ORDER = 'on_order';
    public const AVAILABILITY_DISCONTINUED = 'discontinued';

    // Extraction method constants
    public const EXTRACTION_SELECTOR = 'selector';
    public const EXTRACTION_LLM = 'llm';
    public const EXTRACTION_MANUAL = 'manual';

    protected $fillable = [
        'uuid',
        'catalog_id',
        'crawl_url_id',
        'duplicate_of_id',
        'locale',
        'sku',
        'ean',
        'manufacturer_ref',
        'name',
        'description',
        'short_description',
        'brand',
        'category',
        'price_ht',
        'price_ttc',
        'tva_rate',
        'currency',
        'price_unit',
        'availability',
        'stock_quantity',
        'min_order_quantity',
        'lead_time',
        'images',
        'main_image_url',
        'documents',
        'specifications',
        'weight_kg',
        'width_cm',
        'height_cm',
        'depth_cm',
        'source_url',
        'source_hash',
        'extraction_method',
        'extraction_confidence',
        'status',
        'is_verified',
        'verified_at',
        'marketplace_visible',
        'marketplace_metadata',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'documents' => 'array',
            'specifications' => 'array',
            'marketplace_metadata' => 'array',
            'is_verified' => 'boolean',
            'marketplace_visible' => 'boolean',
            'verified_at' => 'datetime',
            'price_ht' => 'decimal:2',
            'price_ttc' => 'decimal:2',
            'tva_rate' => 'decimal:2',
            'weight_kg' => 'decimal:3',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'depth_cm' => 'decimal:2',
            'extraction_confidence' => 'float',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (FabricantProduct $product) {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });

        // Auto-calculate price_ttc when price_ht is set
        static::saving(function (FabricantProduct $product) {
            if ($product->isDirty('price_ht') && $product->price_ht !== null) {
                $product->price_ttc = $product->price_ht * (1 + $product->tva_rate / 100);
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    /**
     * The catalog this product belongs to.
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(FabricantCatalog::class, 'catalog_id');
    }

    /**
     * The source URL from the crawl.
     */
    public function crawlUrl(): BelongsTo
    {
        return $this->belongsTo(WebCrawlUrl::class, 'crawl_url_id');
    }

    /**
     * Get the fabricant through the catalog.
     */
    public function fabricant()
    {
        return $this->hasOneThrough(
            User::class,
            FabricantCatalog::class,
            'id',           // Foreign key on fabricant_catalogs
            'id',           // Foreign key on users
            'catalog_id',   // Local key on fabricant_products
            'fabricant_id'  // Local key on fabricant_catalogs
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeMarketplaceVisible($query)
    {
        return $query->where('marketplace_visible', true)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopePendingReview($query)
    {
        return $query->where('status', self::STATUS_PENDING_REVIEW);
    }

    public function scopeInStock($query)
    {
        return $query->where('availability', self::AVAILABILITY_IN_STOCK);
    }

    public function scopeWithPrice($query)
    {
        return $query->whereNotNull('price_ht');
    }

    public function scopeForCatalog($query, int $catalogId)
    {
        return $query->where('catalog_id', $catalogId);
    }

    public function scopeDuplicates($query)
    {
        return $query->whereNotNull('duplicate_of_id');
    }

    public function scopeOriginals($query)
    {
        return $query->whereNull('duplicate_of_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // DUPLICATE DETECTION
    // ─────────────────────────────────────────────────────────────────

    /**
     * Find potential duplicates of this product within the same catalog.
     * Excludes products with different locales (language variants are not duplicates).
     */
    public function findPotentialDuplicates(): \Illuminate\Database\Eloquent\Collection
    {
        $query = static::where('catalog_id', $this->catalog_id)
            ->where('id', '!=', $this->id)
            ->whereNull('duplicate_of_id');

        // If this product has a locale, only find duplicates with same locale or no locale
        // Products with different locales are language variants, not duplicates
        if ($this->locale) {
            $query->where(function ($q) {
                $q->where('locale', $this->locale)
                    ->orWhereNull('locale');
            });
        }

        return $query->where(function ($q) {
            // Same SKU
            if ($this->sku) {
                $q->orWhere('sku', $this->sku);
            }
            // Same EAN
            if ($this->ean) {
                $q->orWhere('ean', $this->ean);
            }
            // Same source hash (identical extracted data)
            if ($this->source_hash) {
                $q->orWhere('source_hash', $this->source_hash);
            }
            // Very similar name (exact match) - only if same locale
            if ($this->name && $this->locale) {
                $q->orWhere(function ($q2) {
                    $q2->where('name', $this->name)
                        ->where('locale', $this->locale);
                });
            } elseif ($this->name) {
                $q->orWhere('name', $this->name);
            }
        })->get();
    }

    /**
     * Get duplicate statistics for a catalog.
     * Name duplicates now exclude different locales (language variants).
     */
    public static function getDuplicateStats(int $catalogId): array
    {
        $total = static::where('catalog_id', $catalogId)->count();

        // Count by SKU duplicates (use havingRaw for PostgreSQL compatibility)
        $skuDuplicates = \DB::table('fabricant_products')
            ->select('sku', \DB::raw('COUNT(*) as cnt'))
            ->where('catalog_id', $catalogId)
            ->whereNotNull('sku')
            ->whereNull('deleted_at')
            ->groupBy('sku')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        // Count by name duplicates - GROUP BY name AND locale to exclude language variants
        $nameDuplicates = \DB::table('fabricant_products')
            ->select('name', 'locale', \DB::raw('COUNT(*) as cnt'))
            ->where('catalog_id', $catalogId)
            ->whereNull('deleted_at')
            ->groupBy('name', 'locale')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        // Count by source_hash duplicates
        $hashDuplicates = \DB::table('fabricant_products')
            ->select('source_hash', \DB::raw('COUNT(*) as cnt'))
            ->where('catalog_id', $catalogId)
            ->whereNotNull('source_hash')
            ->whereNull('deleted_at')
            ->groupBy('source_hash')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        // Count language variants (same name, different locale)
        $languageVariants = \DB::table('fabricant_products')
            ->select('name', \DB::raw('COUNT(DISTINCT locale) as locale_count'))
            ->where('catalog_id', $catalogId)
            ->whereNotNull('locale')
            ->whereNull('deleted_at')
            ->groupBy('name')
            ->havingRaw('COUNT(DISTINCT locale) > 1')
            ->get();

        return [
            'total_products' => $total,
            'duplicate_skus' => $skuDuplicates->count(),
            'duplicate_sku_products' => $skuDuplicates->sum('cnt') - $skuDuplicates->count(),
            'duplicate_names' => $nameDuplicates->count(),
            'duplicate_name_products' => $nameDuplicates->sum('cnt') - $nameDuplicates->count(),
            'duplicate_hashes' => $hashDuplicates->count(),
            'duplicate_hash_products' => $hashDuplicates->sum('cnt') - $hashDuplicates->count(),
            'language_variants' => $languageVariants->count(),
        ];
    }

    /**
     * Detect and set locale using LanguageDetector.
     */
    public function detectLocale(): ?string
    {
        $detector = app(\App\Services\Marketplace\LanguageDetector::class);

        return $detector->detect(
            $this->source_url,
            $this->sku,
            $this->description ?? $this->name
        );
    }

    /**
     * Detect and save locale.
     */
    public function detectAndSaveLocale(): void
    {
        $locale = $this->detectLocale();
        if ($locale && $locale !== $this->locale) {
            $this->update(['locale' => $locale]);
        }
    }

    /**
     * Mark this product as a duplicate of another.
     */
    public function markAsDuplicateOf(self $original): void
    {
        $this->update([
            'duplicate_of_id' => $original->id,
            'status' => self::STATUS_ARCHIVED,
        ]);
    }

    /**
     * The original product if this is a duplicate.
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /**
     * Products that are duplicates of this one.
     */
    public function duplicates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // SEARCH & MATCHING
    // ─────────────────────────────────────────────────────────────────

    /**
     * Search products by SKU (exact match).
     */
    public static function findBySku(string $sku): ?self
    {
        return static::where('sku', $sku)
            ->active()
            ->first();
    }

    /**
     * Search products by EAN.
     */
    public static function findByEan(string $ean): ?self
    {
        return static::where('ean', $ean)
            ->active()
            ->first();
    }

    /**
     * Search products by name (fuzzy search).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function searchByLabel(string $query, int $limit = 10)
    {
        return static::active()
            ->where(function ($q) use ($query) {
                $q->where('name', 'ILIKE', "%{$query}%")
                    ->orWhere('description', 'ILIKE', "%{$query}%")
                    ->orWhere('sku', 'ILIKE', "%{$query}%");
            })
            ->limit($limit)
            ->get();
    }

    /**
     * Get product identifier (SKU, EAN, or manufacturer ref).
     */
    public function getIdentifier(): ?string
    {
        return $this->sku ?? $this->ean ?? $this->manufacturer_ref;
    }

    // ─────────────────────────────────────────────────────────────────
    // PRICING HELPERS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get formatted price HT.
     */
    public function getFormattedPriceHt(): ?string
    {
        if ($this->price_ht === null) {
            return null;
        }

        return number_format($this->price_ht, 2, ',', ' ') . ' € HT';
    }

    /**
     * Get formatted price TTC.
     */
    public function getFormattedPriceTtc(): ?string
    {
        if ($this->price_ttc === null) {
            return null;
        }

        return number_format($this->price_ttc, 2, ',', ' ') . ' € TTC';
    }

    /**
     * Get full price string with unit.
     */
    public function getFullPriceString(): ?string
    {
        if ($this->price_ht === null) {
            return null;
        }

        $price = $this->getFormattedPriceHt();

        if ($this->price_unit) {
            $price .= ' / ' . $this->price_unit;
        }

        return $price;
    }

    // ─────────────────────────────────────────────────────────────────
    // STATUS HELPERS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Mark product as verified.
     */
    public function verify(): void
    {
        $this->update([
            'is_verified' => true,
            'verified_at' => now(),
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Check if product has complete data.
     */
    public function isComplete(): bool
    {
        return $this->name !== null
            && $this->price_ht !== null
            && ($this->sku !== null || $this->ean !== null);
    }

    /**
     * Get completeness score (0-100).
     */
    public function getCompletenessScore(): int
    {
        $fields = [
            'name' => 15,
            'description' => 10,
            'sku' => 15,
            'price_ht' => 15,
            'main_image_url' => 10,
            'category' => 10,
            'specifications' => 10,
            'availability' => 5,
            'brand' => 5,
            'price_unit' => 5,
        ];

        $score = 0;
        foreach ($fields as $field => $points) {
            $value = $this->{$field};
            if ($value !== null && $value !== '' && $value !== []) {
                $score += $points;
            }
        }

        return $score;
    }

    // ─────────────────────────────────────────────────────────────────
    // API RESPONSE
    // ─────────────────────────────────────────────────────────────────

    /**
     * Convert to API response format.
     */
    public function toApiResponse(): array
    {
        return [
            'id' => $this->uuid,
            'sku' => $this->sku,
            'ean' => $this->ean,
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand,
            'category' => $this->category,
            'price' => [
                'ht' => $this->price_ht,
                'ttc' => $this->price_ttc,
                'currency' => $this->currency,
                'unit' => $this->price_unit,
                'formatted' => $this->getFullPriceString(),
            ],
            'availability' => $this->availability,
            'stock_quantity' => $this->stock_quantity,
            'min_order_quantity' => $this->min_order_quantity,
            'lead_time' => $this->lead_time,
            'images' => [
                'main' => $this->main_image_url,
                'gallery' => $this->images ?? [],
            ],
            'documents' => $this->documents ?? [],
            'specifications' => $this->specifications ?? [],
            'dimensions' => [
                'weight_kg' => $this->weight_kg,
                'width_cm' => $this->width_cm,
                'height_cm' => $this->height_cm,
                'depth_cm' => $this->depth_cm,
            ],
            'source_url' => $this->source_url,
            'is_verified' => $this->is_verified,
            'completeness_score' => $this->getCompletenessScore(),
        ];
    }

    /**
     * Convert to catalog format for SKU matching.
     */
    public function toCatalogFormat(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'ean' => $this->ean,
            'name' => $this->name,
            'label' => $this->name,
            'price_ht' => $this->price_ht,
            'unit_price' => $this->price_ht,
            'unit' => $this->price_unit,
            'category' => $this->category,
            'brand' => $this->brand,
        ];
    }
}
