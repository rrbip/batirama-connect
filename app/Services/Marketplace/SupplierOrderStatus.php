<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * Statut d'une commande fournisseur.
 */
class SupplierOrderStatus
{
    // Statuts possibles
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PARTIALLY_SHIPPED = 'partially_shipped';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        /** Référence de commande fournisseur */
        public readonly string $supplierOrderRef,

        /** Statut actuel */
        public readonly string $status,

        /** Date de dernière mise à jour */
        public readonly \DateTimeInterface $updatedAt,

        /** Date de livraison estimée */
        public readonly ?\DateTimeInterface $estimatedDelivery = null,

        /** Informations d'expédition si applicable */
        public readonly ?array $shipmentInfo = null,

        /** Notes ou commentaires du fournisseur */
        public readonly ?string $notes = null,

        /** Métadonnées supplémentaires */
        public readonly array $metadata = [],
    ) {}

    /**
     * Indique si la commande est terminée (livrée ou annulée).
     */
    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
        ], true);
    }

    /**
     * Indique si la commande est en cours de livraison.
     */
    public function isInTransit(): bool
    {
        return in_array($this->status, [
            self::STATUS_PARTIALLY_SHIPPED,
            self::STATUS_SHIPPED,
        ], true);
    }

    /**
     * Indique si la commande peut être annulée.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
        ], true);
    }

    /**
     * Libellé du statut.
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'En attente',
            self::STATUS_CONFIRMED => 'Confirmée',
            self::STATUS_PROCESSING => 'En préparation',
            self::STATUS_PARTIALLY_SHIPPED => 'Partiellement expédiée',
            self::STATUS_SHIPPED => 'Expédiée',
            self::STATUS_DELIVERED => 'Livrée',
            self::STATUS_CANCELLED => 'Annulée',
            self::STATUS_FAILED => 'Échec',
            default => $this->status,
        };
    }

    /**
     * Conversion en tableau.
     */
    public function toArray(): array
    {
        return [
            'supplier_order_ref' => $this->supplierOrderRef,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'estimated_delivery' => $this->estimatedDelivery?->format('Y-m-d'),
            'shipment_info' => $this->shipmentInfo,
            'notes' => $this->notes,
            'is_final' => $this->isFinal(),
            'can_be_cancelled' => $this->canBeCancelled(),
        ];
    }
}
