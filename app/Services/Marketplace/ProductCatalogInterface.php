<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * Interface pour les catalogues produits marketplace.
 *
 * Permet d'abstraire différentes sources de catalogue:
 * - API BATIRAMA marketplace
 * - Catalogue local/CSV
 * - Catalogue fournisseur externe
 */
interface ProductCatalogInterface
{
    /**
     * Recherche un produit par son SKU exact.
     *
     * @param string $sku Le code SKU à rechercher
     * @return array|null Le produit trouvé ou null
     */
    public function findBySku(string $sku): ?array;

    /**
     * Recherche des produits par label/nom.
     *
     * @param string $query Le terme de recherche
     * @param int $limit Nombre max de résultats
     * @return array Liste de produits correspondants
     */
    public function searchByLabel(string $query, int $limit = 10): array;

    /**
     * Recherche des produits par catégorie.
     *
     * @param string $category La catégorie
     * @param int $limit Nombre max de résultats
     * @return array Liste de produits
     */
    public function findByCategory(string $category, int $limit = 50): array;

    /**
     * Récupère un produit par son ID.
     *
     * @param int|string $id L'identifiant du produit
     * @return array|null Le produit ou null
     */
    public function findById(int|string $id): ?array;

    /**
     * Vérifie la disponibilité d'un produit.
     *
     * @param string $sku Le code SKU
     * @param float $quantity La quantité demandée
     * @return array Infos de disponibilité ['available' => bool, 'quantity' => float, 'delivery_days' => int]
     */
    public function checkAvailability(string $sku, float $quantity): array;

    /**
     * Récupère les prix pour une liste de SKUs.
     *
     * @param array $skus Liste de codes SKU
     * @return array Prix par SKU ['SKU1' => ['price_ht' => float, 'price_ttc' => float], ...]
     */
    public function getPrices(array $skus): array;
}
