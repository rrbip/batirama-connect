<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceShipment;
use App\Notifications\MarketplaceShipmentNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher de commandes vers les fournisseurs.
 *
 * Responsable de:
 * - Router les commandes vers le(s) bon(s) fournisseur(s)
 * - Gérer le multi-sourcing (plusieurs fournisseurs par commande)
 * - Suivre l'état des commandes fournisseurs
 * - Créer les expéditions
 */
class OrderDispatcher
{
    /** @var Collection<SupplierInterface> */
    private Collection $suppliers;

    public function __construct()
    {
        $this->suppliers = collect();
    }

    /**
     * Enregistre un fournisseur.
     */
    public function registerSupplier(SupplierInterface $supplier): void
    {
        $this->suppliers->put($supplier->getIdentifier(), $supplier);
    }

    /**
     * Récupère un fournisseur par son identifiant.
     */
    public function getSupplier(string $identifier): ?SupplierInterface
    {
        return $this->suppliers->get($identifier);
    }

    /**
     * Liste tous les fournisseurs disponibles.
     */
    public function getAvailableSuppliers(): Collection
    {
        return $this->suppliers->filter(fn (SupplierInterface $s) => $s->isAvailable());
    }

    /**
     * Dispatche une commande validée vers les fournisseurs.
     *
     * @param MarketplaceOrder $order La commande à dispatcher
     * @return DispatchResult Résultat du dispatch
     */
    public function dispatch(MarketplaceOrder $order): DispatchResult
    {
        if ($order->status !== MarketplaceOrder::STATUS_VALIDATED) {
            throw new \InvalidArgumentException('Seules les commandes validées peuvent être dispatchées');
        }

        // Récupérer les items à commander
        $items = $order->items()
            ->where('line_status', 'included')
            ->whereIn('match_status', ['matched', 'manual'])
            ->get();

        if ($items->isEmpty()) {
            return DispatchResult::failure('Aucun item à commander');
        }

        // Grouper les items par fournisseur optimal
        $itemsBySupplier = $this->routeItemsToSuppliers($items);

        if ($itemsBySupplier->isEmpty()) {
            return DispatchResult::failure('Aucun fournisseur disponible pour ces produits');
        }

        // Transmettre à chaque fournisseur
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($itemsBySupplier as $supplierId => $supplierItems) {
            $supplier = $this->getSupplier($supplierId);
            if (!$supplier) {
                $failureCount++;
                continue;
            }

            try {
                $result = $supplier->submitOrder($order, $supplierItems);
                $results[$supplierId] = $result;

                if ($result->success) {
                    $successCount++;

                    // Créer l'expédition
                    $shipment = MarketplaceShipment::create([
                        'order_id' => $order->id,
                        'supplier_id' => $supplierId,
                        'supplier_name' => $supplier->getName(),
                        'supplier_order_ref' => $result->supplierOrderRef,
                        'status' => MarketplaceShipment::STATUS_PENDING,
                        'estimated_delivery_at' => $result->estimatedDelivery,
                        'metadata' => [
                            'items_count' => count($supplierItems),
                            'confirmed_total_ht' => $result->confirmedTotalHt,
                        ],
                    ]);

                    Log::info('Order dispatched to supplier', [
                        'order_id' => $order->uuid,
                        'supplier' => $supplierId,
                        'supplier_order_ref' => $result->supplierOrderRef,
                        'shipment_id' => $shipment->uuid,
                    ]);
                } else {
                    $failureCount++;
                    Log::error('Failed to dispatch to supplier', [
                        'order_id' => $order->uuid,
                        'supplier' => $supplierId,
                        'error' => $result->errorMessage,
                    ]);
                }
            } catch (\Throwable $e) {
                $failureCount++;
                $results[$supplierId] = SupplierOrderResult::failure(
                    $e->getMessage(),
                    'exception'
                );

                Log::error('Exception dispatching to supplier', [
                    'order_id' => $order->uuid,
                    'supplier' => $supplierId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // Mettre à jour le statut de la commande
        if ($successCount > 0) {
            $order->update([
                'status' => MarketplaceOrder::STATUS_PROCESSING,
                'ordered_at' => now(),
            ]);
        }

        return new DispatchResult(
            success: $successCount > 0,
            totalSuppliers: $itemsBySupplier->count(),
            successCount: $successCount,
            failureCount: $failureCount,
            supplierResults: $results
        );
    }

    /**
     * Route les items vers les fournisseurs optimaux.
     *
     * Stratégie de routage:
     * 1. Chercher le fournisseur qui gère la catégorie du produit
     * 2. Vérifier la disponibilité
     * 3. Comparer les prix si plusieurs options
     *
     * @param Collection $items
     * @return Collection Items groupés par supplier_id
     */
    private function routeItemsToSuppliers(Collection $items): Collection
    {
        $routing = collect();

        foreach ($items as $item) {
            // Trouver le meilleur fournisseur pour cet item
            $bestSupplier = $this->findBestSupplier($item);

            if ($bestSupplier) {
                if (!$routing->has($bestSupplier->getIdentifier())) {
                    $routing->put($bestSupplier->getIdentifier(), []);
                }

                $routing[$bestSupplier->getIdentifier()][] = [
                    'item_id' => $item->id,
                    'sku' => $item->product_sku,
                    'name' => $item->product_name,
                    'quantity' => $item->effective_quantity,
                    'unit_price_ht' => $item->unit_price_ht,
                ];
            }
        }

        return $routing;
    }

    /**
     * Trouve le meilleur fournisseur pour un item.
     */
    private function findBestSupplier(MarketplaceOrderItem $item): ?SupplierInterface
    {
        $category = $item->metadata['category'] ?? null;
        $sku = $item->product_sku;
        $quantity = $item->effective_quantity;

        $availableSuppliers = $this->getAvailableSuppliers();

        if ($availableSuppliers->isEmpty()) {
            return null;
        }

        // Si un seul fournisseur, le retourner
        if ($availableSuppliers->count() === 1) {
            return $availableSuppliers->first();
        }

        // Filtrer par catégorie si disponible
        if ($category) {
            $categorySuppliers = $availableSuppliers->filter(
                fn (SupplierInterface $s) => $s->handlesCategory($category)
            );

            if ($categorySuppliers->isNotEmpty()) {
                $availableSuppliers = $categorySuppliers;
            }
        }

        // Pour l'instant, prendre le premier disponible
        // TODO: Comparer prix et disponibilité pour optimiser
        return $availableSuppliers->first();
    }

    /**
     * Met à jour les statuts des expéditions depuis les fournisseurs.
     *
     * À appeler périodiquement (cron job) pour synchroniser les statuts.
     */
    public function syncShipmentStatuses(MarketplaceOrder $order): void
    {
        $shipments = $order->shipments()->whereNotIn('status', [
            MarketplaceShipment::STATUS_DELIVERED,
            MarketplaceShipment::STATUS_FAILED,
        ])->get();

        foreach ($shipments as $shipment) {
            $supplier = $this->getSupplier($shipment->supplier_id);
            if (!$supplier) {
                continue;
            }

            try {
                $status = $supplier->getOrderStatus($shipment->supplier_order_ref);

                // Mettre à jour le statut
                $newStatus = $this->mapSupplierStatus($status->status);
                if ($newStatus !== $shipment->status) {
                    $oldStatus = $shipment->status;
                    $shipment->status = $newStatus;

                    // Mettre à jour les dates
                    if ($status->shipmentInfo) {
                        $shipment->carrier_name = $status->shipmentInfo['carrier'] ?? $shipment->carrier_name;
                        $shipment->carrier_tracking_number = $status->shipmentInfo['tracking_number'] ?? $shipment->carrier_tracking_number;
                        $shipment->carrier_tracking_url = $status->shipmentInfo['tracking_url'] ?? $shipment->carrier_tracking_url;

                        if (isset($status->shipmentInfo['shipped_at'])) {
                            $shipment->shipped_at = $status->shipmentInfo['shipped_at'];
                        }
                        if (isset($status->shipmentInfo['delivered_at'])) {
                            $shipment->delivered_at = $status->shipmentInfo['delivered_at'];
                        }
                    }

                    $shipment->estimated_delivery_at = $status->estimatedDelivery ?? $shipment->estimated_delivery_at;
                    $shipment->save();

                    // Notifier si changement significatif
                    $this->notifyStatusChange($order, $shipment, $oldStatus, $newStatus);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to sync shipment status', [
                    'shipment_id' => $shipment->uuid,
                    'supplier' => $shipment->supplier_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Vérifier si toutes les expéditions sont terminées
        $this->checkOrderCompletion($order);
    }

    /**
     * Mappe un statut fournisseur vers un statut d'expédition.
     */
    private function mapSupplierStatus(string $supplierStatus): string
    {
        return match ($supplierStatus) {
            SupplierOrderStatus::STATUS_PENDING,
            SupplierOrderStatus::STATUS_CONFIRMED => MarketplaceShipment::STATUS_PENDING,
            SupplierOrderStatus::STATUS_PROCESSING => MarketplaceShipment::STATUS_PREPARING,
            SupplierOrderStatus::STATUS_SHIPPED,
            SupplierOrderStatus::STATUS_PARTIALLY_SHIPPED => MarketplaceShipment::STATUS_SHIPPED,
            SupplierOrderStatus::STATUS_DELIVERED => MarketplaceShipment::STATUS_DELIVERED,
            SupplierOrderStatus::STATUS_CANCELLED,
            SupplierOrderStatus::STATUS_FAILED => MarketplaceShipment::STATUS_FAILED,
            default => MarketplaceShipment::STATUS_PENDING,
        };
    }

    /**
     * Notifie l'artisan d'un changement de statut significatif.
     */
    private function notifyStatusChange(
        MarketplaceOrder $order,
        MarketplaceShipment $shipment,
        string $oldStatus,
        string $newStatus
    ): void {
        $event = match ($newStatus) {
            MarketplaceShipment::STATUS_SHIPPED => 'shipped',
            MarketplaceShipment::STATUS_DELIVERED => 'delivered',
            MarketplaceShipment::STATUS_FAILED => 'failed',
            default => null,
        };

        if ($event && $order->artisan) {
            $order->artisan->notify(
                new MarketplaceShipmentNotification($order, $shipment, $event)
            );
        }
    }

    /**
     * Vérifie si la commande est complète (toutes expéditions terminées).
     */
    private function checkOrderCompletion(MarketplaceOrder $order): void
    {
        $pendingShipments = $order->shipments()
            ->whereNotIn('status', [
                MarketplaceShipment::STATUS_DELIVERED,
                MarketplaceShipment::STATUS_FAILED,
            ])
            ->count();

        if ($pendingShipments === 0) {
            $allDelivered = $order->shipments()
                ->where('status', MarketplaceShipment::STATUS_DELIVERED)
                ->count() === $order->shipments()->count();

            $order->update([
                'status' => $allDelivered
                    ? MarketplaceOrder::STATUS_DELIVERED
                    : MarketplaceOrder::STATUS_SHIPPED,
                'delivered_at' => $allDelivered ? now() : null,
            ]);
        }
    }
}
