<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceOrder;
use App\Models\MarketplaceShipment;

/**
 * Interface pour les intégrations fournisseurs marketplace.
 *
 * Permet d'abstraire les différents fournisseurs de matériaux:
 * - BATIRAMA marketplace
 * - Grossistes régionaux
 * - Fournisseurs spécialisés (peinture, placo, etc.)
 */
interface SupplierInterface
{
    /**
     * Identifiant unique du fournisseur.
     */
    public function getIdentifier(): string;

    /**
     * Nom affiché du fournisseur.
     */
    public function getName(): string;

    /**
     * Vérifie si le fournisseur est disponible/actif.
     */
    public function isAvailable(): bool;

    /**
     * Vérifie la disponibilité des produits.
     *
     * @param array $items Liste des items à vérifier [['sku' => '...', 'quantity' => ...], ...]
     * @return array Disponibilité par SKU ['SKU1' => ['available' => true, 'quantity' => 10, 'delivery_days' => 2], ...]
     */
    public function checkAvailability(array $items): array;

    /**
     * Récupère les prix actuels pour une liste de SKUs.
     *
     * @param array $skus Liste des codes SKU
     * @return array Prix par SKU ['SKU1' => ['price_ht' => 10.00, 'price_ttc' => 12.00], ...]
     */
    public function getPrices(array $skus): array;

    /**
     * Transmet une commande au fournisseur.
     *
     * @param MarketplaceOrder $order La commande à transmettre
     * @param array $items Les items à commander (subset de la commande pour ce fournisseur)
     * @return SupplierOrderResult Résultat de la transmission
     */
    public function submitOrder(MarketplaceOrder $order, array $items): SupplierOrderResult;

    /**
     * Récupère le statut d'une commande fournisseur.
     *
     * @param string $supplierOrderRef Référence de commande chez le fournisseur
     * @return SupplierOrderStatus Statut de la commande
     */
    public function getOrderStatus(string $supplierOrderRef): SupplierOrderStatus;

    /**
     * Récupère les informations d'expédition.
     *
     * @param string $supplierOrderRef Référence de commande chez le fournisseur
     * @return array|null Infos d'expédition ou null si pas encore expédiée
     */
    public function getShipmentInfo(string $supplierOrderRef): ?array;

    /**
     * Annule une commande chez le fournisseur.
     *
     * @param string $supplierOrderRef Référence de commande chez le fournisseur
     * @param string|null $reason Raison de l'annulation
     * @return bool Succès de l'annulation
     */
    public function cancelOrder(string $supplierOrderRef, ?string $reason = null): bool;

    /**
     * Retourne les catégories de produits gérées par ce fournisseur.
     *
     * @return array Liste des catégories ['peinture', 'placo', 'outillage', ...]
     */
    public function getCategories(): array;

    /**
     * Vérifie si le fournisseur gère une catégorie spécifique.
     */
    public function handlesCategory(string $category): bool;
}
