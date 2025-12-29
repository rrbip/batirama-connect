<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * Implémentation in-memory du catalogue produits.
 *
 * Utilisé pour:
 * - Tests unitaires
 * - Développement local
 * - Démonstrations
 */
class InMemoryProductCatalog implements ProductCatalogInterface
{
    /** @var array Liste des produits */
    private array $products = [];

    /** @var array Index par SKU */
    private array $skuIndex = [];

    public function __construct(array $products = [])
    {
        $this->loadProducts($products);
    }

    /**
     * Charge les produits et construit les index.
     */
    public function loadProducts(array $products): void
    {
        $this->products = [];
        $this->skuIndex = [];

        foreach ($products as $product) {
            $id = $product['id'] ?? count($this->products) + 1;
            $product['id'] = $id;
            $this->products[$id] = $product;

            if (isset($product['sku'])) {
                $this->skuIndex[strtoupper($product['sku'])] = $id;
            }
        }
    }

    /**
     * Charge un catalogue de produits peinture/bâtiment par défaut.
     */
    public function loadDefaultCatalog(): void
    {
        $this->loadProducts([
            [
                'id' => 1,
                'sku' => 'PEINT-ACR-BL-10L',
                'name' => 'Peinture acrylique blanche mate 10L',
                'category' => 'peinture',
                'price_ht' => 45.00,
                'unit' => 'seau',
            ],
            [
                'id' => 2,
                'sku' => 'PEINT-ACR-BL-5L',
                'name' => 'Peinture acrylique blanche mate 5L',
                'category' => 'peinture',
                'price_ht' => 25.00,
                'unit' => 'seau',
            ],
            [
                'id' => 3,
                'sku' => 'PEINT-SAT-BL-10L',
                'name' => 'Peinture satinée blanche 10L',
                'category' => 'peinture',
                'price_ht' => 55.00,
                'unit' => 'seau',
            ],
            [
                'id' => 4,
                'sku' => 'ENDUIT-LIS-25KG',
                'name' => 'Enduit de lissage en poudre 25kg',
                'category' => 'enduit',
                'price_ht' => 18.50,
                'unit' => 'sac',
            ],
            [
                'id' => 5,
                'sku' => 'ENDUIT-REB-5KG',
                'name' => 'Enduit de rebouchage 5kg',
                'category' => 'enduit',
                'price_ht' => 12.00,
                'unit' => 'pot',
            ],
            [
                'id' => 6,
                'sku' => 'BANDE-JOINT-50M',
                'name' => 'Bande à joint papier 50m',
                'category' => 'accessoire',
                'price_ht' => 8.50,
                'unit' => 'rouleau',
            ],
            [
                'id' => 7,
                'sku' => 'PRIMAIRE-ACCR-5L',
                'name' => 'Primaire d\'accrochage universel 5L',
                'category' => 'primaire',
                'price_ht' => 35.00,
                'unit' => 'bidon',
            ],
            [
                'id' => 8,
                'sku' => 'ROULEAU-MEL-180',
                'name' => 'Rouleau méché anti-goutte 180mm',
                'category' => 'outillage',
                'price_ht' => 6.50,
                'unit' => 'unité',
            ],
            [
                'id' => 9,
                'sku' => 'PLACO-BA13-STD',
                'name' => 'Plaque de plâtre BA13 standard 2500x1200',
                'category' => 'placo',
                'price_ht' => 8.20,
                'unit' => 'plaque',
            ],
            [
                'id' => 10,
                'sku' => 'PLACO-BA13-HYD',
                'name' => 'Plaque de plâtre BA13 hydrofuge 2500x1200',
                'category' => 'placo',
                'price_ht' => 12.50,
                'unit' => 'plaque',
            ],
            [
                'id' => 11,
                'sku' => 'JOINT-PLACO-25KG',
                'name' => 'Enduit pour joint placo 25kg',
                'category' => 'placo',
                'price_ht' => 14.00,
                'unit' => 'sac',
            ],
            [
                'id' => 12,
                'sku' => 'PEINT-FAC-BL-15L',
                'name' => 'Peinture façade blanche 15L',
                'category' => 'peinture',
                'price_ht' => 85.00,
                'unit' => 'seau',
            ],
            [
                'id' => 13,
                'sku' => 'PEINT-SOL-GRIS-5L',
                'name' => 'Peinture sol grise polyuréthane 5L',
                'category' => 'peinture',
                'price_ht' => 65.00,
                'unit' => 'pot',
            ],
            [
                'id' => 14,
                'sku' => 'SCOTCH-MASK-50M',
                'name' => 'Ruban de masquage 50m',
                'category' => 'accessoire',
                'price_ht' => 3.50,
                'unit' => 'rouleau',
            ],
            [
                'id' => 15,
                'sku' => 'BACHE-PROT-4X5',
                'name' => 'Bâche de protection 4x5m',
                'category' => 'accessoire',
                'price_ht' => 5.00,
                'unit' => 'unité',
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function findBySku(string $sku): ?array
    {
        $normalizedSku = strtoupper(trim($sku));

        if (isset($this->skuIndex[$normalizedSku])) {
            return $this->products[$this->skuIndex[$normalizedSku]];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function searchByLabel(string $query, int $limit = 10): array
    {
        if (empty($query)) {
            return [];
        }

        $normalizedQuery = mb_strtolower($query);
        $words = array_filter(explode(' ', $normalizedQuery), fn($w) => strlen($w) > 2);

        $results = [];

        foreach ($this->products as $product) {
            $name = mb_strtolower($product['name'] ?? '');
            $category = mb_strtolower($product['category'] ?? '');

            // Vérifier si au moins un mot correspond
            $matchCount = 0;
            foreach ($words as $word) {
                if (str_contains($name, $word) || str_contains($category, $word)) {
                    $matchCount++;
                }
            }

            if ($matchCount > 0) {
                $results[] = [
                    'product' => $product,
                    'relevance' => $matchCount / count($words),
                ];
            }
        }

        // Trier par pertinence
        usort($results, fn($a, $b) => $b['relevance'] <=> $a['relevance']);

        return array_slice(
            array_map(fn($r) => $r['product'], $results),
            0,
            $limit
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findByCategory(string $category, int $limit = 50): array
    {
        $normalizedCategory = mb_strtolower(trim($category));

        return array_slice(
            array_filter(
                $this->products,
                fn($p) => mb_strtolower($p['category'] ?? '') === $normalizedCategory
            ),
            0,
            $limit
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int|string $id): ?array
    {
        return $this->products[$id] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function checkAvailability(string $sku, float $quantity): array
    {
        $product = $this->findBySku($sku);

        if (!$product) {
            return [
                'available' => false,
                'quantity' => 0,
                'delivery_days' => null,
                'message' => 'Produit non trouvé',
            ];
        }

        // Simulation: toujours disponible dans le mock
        return [
            'available' => true,
            'quantity' => $quantity,
            'delivery_days' => 2,
            'message' => 'En stock',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getPrices(array $skus): array
    {
        $prices = [];

        foreach ($skus as $sku) {
            $product = $this->findBySku($sku);
            if ($product) {
                $priceHt = $product['price_ht'] ?? 0;
                $prices[$sku] = [
                    'price_ht' => $priceHt,
                    'price_ttc' => round($priceHt * 1.20, 2), // TVA 20%
                ];
            }
        }

        return $prices;
    }

    /**
     * Ajoute un produit au catalogue.
     */
    public function addProduct(array $product): void
    {
        $id = $product['id'] ?? count($this->products) + 1;
        $product['id'] = $id;
        $this->products[$id] = $product;

        if (isset($product['sku'])) {
            $this->skuIndex[strtoupper($product['sku'])] = $id;
        }
    }

    /**
     * Retourne tous les produits.
     */
    public function all(): array
    {
        return array_values($this->products);
    }

    /**
     * Compte le nombre de produits.
     */
    public function count(): int
    {
        return count($this->products);
    }
}
