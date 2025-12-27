<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\FabricantProduct;
use Illuminate\Support\Collection;

/**
 * Catalog adapter for fabricant products.
 *
 * Implements ProductCatalogInterface using FabricantProduct models
 * as the data source. This allows the SKU matching service to work
 * with products extracted from fabricant websites.
 */
class FabricantProductCatalog implements ProductCatalogInterface
{
    private ?int $fabricantId = null;
    private ?int $catalogId = null;

    /**
     * Set the fabricant ID to filter products.
     */
    public function forFabricant(int $fabricantId): self
    {
        $this->fabricantId = $fabricantId;
        return $this;
    }

    /**
     * Set the catalog ID to filter products.
     */
    public function forCatalog(int $catalogId): self
    {
        $this->catalogId = $catalogId;
        return $this;
    }

    /**
     * Get base query with filters applied.
     */
    private function baseQuery()
    {
        $query = FabricantProduct::active()->marketplaceVisible();

        if ($this->catalogId) {
            $query->where('catalog_id', $this->catalogId);
        } elseif ($this->fabricantId) {
            $query->whereHas('catalog', function ($q) {
                $q->where('fabricant_id', $this->fabricantId);
            });
        }

        return $query;
    }

    /**
     * {@inheritdoc}
     */
    public function findBySku(string $sku): ?array
    {
        $product = $this->baseQuery()
            ->where(function ($q) use ($sku) {
                $q->where('sku', $sku)
                    ->orWhere('ean', $sku)
                    ->orWhere('manufacturer_ref', $sku);
            })
            ->first();

        return $product?->toCatalogFormat();
    }

    /**
     * {@inheritdoc}
     */
    public function searchByLabel(string $query, int $limit = 10): array
    {
        $searchTerms = $this->normalizeSearchTerms($query);

        $products = $this->baseQuery()
            ->where(function ($q) use ($searchTerms, $query) {
                // Exact name match (higher priority)
                $q->where('name', 'ILIKE', "%{$query}%");

                // Also search in description and category
                $q->orWhere('description', 'ILIKE', "%{$query}%");
                $q->orWhere('category', 'ILIKE', "%{$query}%");
                $q->orWhere('brand', 'ILIKE', "%{$query}%");

                // Search individual terms
                foreach ($searchTerms as $term) {
                    if (strlen($term) > 2) {
                        $q->orWhere('name', 'ILIKE', "%{$term}%");
                    }
                }
            })
            ->orderByRaw("
                CASE
                    WHEN name ILIKE ? THEN 1
                    WHEN name ILIKE ? THEN 2
                    WHEN sku ILIKE ? THEN 3
                    ELSE 4
                END
            ", ["{$query}", "%{$query}%", "%{$query}%"])
            ->limit($limit)
            ->get();

        return $products->map(fn($p) => $p->toCatalogFormat())->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function findByCategory(string $category, int $limit = 50): array
    {
        $products = $this->baseQuery()
            ->where('category', 'ILIKE', "%{$category}%")
            ->limit($limit)
            ->get();

        return $products->map(fn($p) => $p->toCatalogFormat())->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int|string $id): ?array
    {
        $product = $this->baseQuery()
            ->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', $id);
                }
                $q->orWhere('uuid', $id);
            })
            ->first();

        return $product?->toCatalogFormat();
    }

    /**
     * {@inheritdoc}
     */
    public function checkAvailability(string $sku, float $quantity): array
    {
        $product = $this->baseQuery()
            ->where(function ($q) use ($sku) {
                $q->where('sku', $sku)
                    ->orWhere('ean', $sku);
            })
            ->first();

        if (!$product) {
            return [
                'available' => false,
                'quantity' => 0,
                'delivery_days' => null,
                'message' => 'Product not found',
            ];
        }

        $available = $product->availability === FabricantProduct::AVAILABILITY_IN_STOCK;
        $stockQty = $product->stock_quantity ?? 0;

        return [
            'available' => $available && ($stockQty >= $quantity || $stockQty === 0),
            'quantity' => $stockQty > 0 ? min($stockQty, $quantity) : $quantity,
            'delivery_days' => $this->parseLeadTime($product->lead_time),
            'status' => $product->availability,
            'min_order_quantity' => $product->min_order_quantity,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getPrices(array $skus): array
    {
        $products = $this->baseQuery()
            ->where(function ($q) use ($skus) {
                $q->whereIn('sku', $skus)
                    ->orWhereIn('ean', $skus);
            })
            ->get();

        $prices = [];
        foreach ($products as $product) {
            $key = $product->sku ?? $product->ean;
            $prices[$key] = [
                'price_ht' => $product->price_ht,
                'price_ttc' => $product->price_ttc,
                'currency' => $product->currency,
                'unit' => $product->price_unit,
            ];
        }

        return $prices;
    }

    /**
     * Get all products for marketplace display.
     *
     * @param array $filters Optional filters ['category', 'brand', 'min_price', 'max_price', 'in_stock']
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return array Paginated products with metadata
     */
    public function listProducts(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = $this->baseQuery();

        if (!empty($filters['category'])) {
            $query->where('category', 'ILIKE', "%{$filters['category']}%");
        }

        if (!empty($filters['brand'])) {
            $query->where('brand', 'ILIKE', "%{$filters['brand']}%");
        }

        if (!empty($filters['min_price'])) {
            $query->where('price_ht', '>=', $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $query->where('price_ht', '<=', $filters['max_price']);
        }

        if (!empty($filters['in_stock'])) {
            $query->where('availability', FabricantProduct::AVAILABILITY_IN_STOCK);
        }

        if (!empty($filters['verified'])) {
            $query->where('is_verified', true);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'ILIKE', "%{$filters['search']}%")
                    ->orWhere('sku', 'ILIKE', "%{$filters['search']}%")
                    ->orWhere('description', 'ILIKE', "%{$filters['search']}%");
            });
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginated->items())->map(fn($p) => $p->toApiResponse())->toArray(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    /**
     * Get available categories.
     */
    public function getCategories(): array
    {
        return $this->baseQuery()
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Get available brands.
     */
    public function getBrands(): array
    {
        return $this->baseQuery()
            ->whereNotNull('brand')
            ->distinct()
            ->pluck('brand')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Normalize search terms for fuzzy matching.
     */
    private function normalizeSearchTerms(string $query): array
    {
        // Split on spaces and filter short words
        $terms = preg_split('/\s+/', $query);
        return array_filter($terms, fn($t) => strlen($t) > 2);
    }

    /**
     * Parse lead time string to days.
     */
    private function parseLeadTime(?string $leadTime): ?int
    {
        if (empty($leadTime)) {
            return null;
        }

        // Try to extract number of days
        if (preg_match('/(\d+)\s*(jour|day|j)/i', $leadTime, $matches)) {
            return (int) $matches[1];
        }

        // Try weeks
        if (preg_match('/(\d+)\s*(semaine|week)/i', $leadTime, $matches)) {
            return (int) $matches[1] * 7;
        }

        return null;
    }
}
