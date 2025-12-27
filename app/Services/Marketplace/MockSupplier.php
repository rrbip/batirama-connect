<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceOrder;
use Carbon\Carbon;

/**
 * Implémentation mock d'un fournisseur pour les tests.
 *
 * Simule les réponses d'un fournisseur réel pour:
 * - Développement local
 * - Tests automatisés
 * - Démonstrations
 */
class MockSupplier implements SupplierInterface
{
    private string $identifier;
    private string $name;
    private bool $available = true;
    private array $categories = [];
    private array $orders = [];

    public function __construct(
        string $identifier = 'mock_supplier',
        string $name = 'Fournisseur Test',
        array $categories = ['peinture', 'enduit', 'placo', 'outillage', 'accessoire']
    ) {
        $this->identifier = $identifier;
        $this->name = $name;
        $this->categories = $categories;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    public function checkAvailability(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $sku = $item['sku'] ?? '';
            $quantity = $item['quantity'] ?? 1;

            // Simulation: tout est disponible
            $result[$sku] = [
                'available' => true,
                'quantity' => $quantity,
                'delivery_days' => rand(1, 5),
                'in_stock' => true,
            ];
        }

        return $result;
    }

    public function getPrices(array $skus): array
    {
        $prices = [];

        foreach ($skus as $sku) {
            // Simulation: prix basé sur le SKU
            $basePrice = crc32($sku) % 100 + 10;
            $prices[$sku] = [
                'price_ht' => (float) $basePrice,
                'price_ttc' => round($basePrice * 1.20, 2),
            ];
        }

        return $prices;
    }

    public function submitOrder(MarketplaceOrder $order, array $items): SupplierOrderResult
    {
        // Générer une référence de commande
        $supplierOrderRef = 'MOCK-' . strtoupper(substr(md5((string) time()), 0, 8));

        // Calculer le total
        $totalHt = 0;
        $itemDetails = [];

        foreach ($items as $item) {
            $sku = $item['sku'] ?? '';
            $quantity = $item['quantity'] ?? 1;
            $price = $item['unit_price_ht'] ?? 10.00;

            $lineTotal = $price * $quantity;
            $totalHt += $lineTotal;

            $itemDetails[$sku] = [
                'accepted' => true,
                'quantity' => $quantity,
                'price' => $price,
                'line_total' => $lineTotal,
            ];
        }

        // Stocker la commande simulée
        $this->orders[$supplierOrderRef] = [
            'order_id' => $order->uuid,
            'status' => SupplierOrderStatus::STATUS_CONFIRMED,
            'items' => $itemDetails,
            'total_ht' => $totalHt,
            'created_at' => now(),
            'estimated_delivery' => now()->addDays(3),
        ];

        return SupplierOrderResult::success(
            supplierOrderRef: $supplierOrderRef,
            itemDetails: $itemDetails,
            confirmedTotalHt: $totalHt,
            estimatedDelivery: now()->addDays(3),
            metadata: [
                'supplier' => $this->identifier,
                'simulated' => true,
            ]
        );
    }

    public function getOrderStatus(string $supplierOrderRef): SupplierOrderStatus
    {
        $order = $this->orders[$supplierOrderRef] ?? null;

        if (!$order) {
            return new SupplierOrderStatus(
                supplierOrderRef: $supplierOrderRef,
                status: SupplierOrderStatus::STATUS_FAILED,
                updatedAt: now(),
                notes: 'Commande non trouvée'
            );
        }

        // Simuler l'évolution du statut
        $createdAt = Carbon::parse($order['created_at']);
        $hoursSince = $createdAt->diffInHours(now());

        $status = match (true) {
            $hoursSince >= 72 => SupplierOrderStatus::STATUS_DELIVERED,
            $hoursSince >= 48 => SupplierOrderStatus::STATUS_SHIPPED,
            $hoursSince >= 24 => SupplierOrderStatus::STATUS_PROCESSING,
            default => SupplierOrderStatus::STATUS_CONFIRMED,
        };

        return new SupplierOrderStatus(
            supplierOrderRef: $supplierOrderRef,
            status: $status,
            updatedAt: now(),
            estimatedDelivery: Carbon::parse($order['estimated_delivery']),
            shipmentInfo: $status === SupplierOrderStatus::STATUS_SHIPPED ? [
                'carrier' => 'DPD',
                'tracking_number' => 'DPD' . substr($supplierOrderRef, 5),
                'tracking_url' => 'https://tracking.dpd.fr/' . substr($supplierOrderRef, 5),
                'shipped_at' => now()->subHours(24),
            ] : null
        );
    }

    public function getShipmentInfo(string $supplierOrderRef): ?array
    {
        $status = $this->getOrderStatus($supplierOrderRef);

        return $status->shipmentInfo;
    }

    public function cancelOrder(string $supplierOrderRef, ?string $reason = null): bool
    {
        if (!isset($this->orders[$supplierOrderRef])) {
            return false;
        }

        $order = $this->orders[$supplierOrderRef];

        // Peut annuler seulement si pas encore expédié
        $createdAt = Carbon::parse($order['created_at']);
        if ($createdAt->diffInHours(now()) >= 48) {
            return false;
        }

        $this->orders[$supplierOrderRef]['status'] = SupplierOrderStatus::STATUS_CANCELLED;
        $this->orders[$supplierOrderRef]['cancellation_reason'] = $reason;

        return true;
    }

    public function getCategories(): array
    {
        return $this->categories;
    }

    public function handlesCategory(string $category): bool
    {
        return in_array(strtolower($category), array_map('strtolower', $this->categories), true);
    }

    /**
     * Méthode de test: force le statut d'une commande.
     */
    public function forceOrderStatus(string $supplierOrderRef, string $status): void
    {
        if (isset($this->orders[$supplierOrderRef])) {
            $this->orders[$supplierOrderRef]['status'] = $status;
        }
    }
}
