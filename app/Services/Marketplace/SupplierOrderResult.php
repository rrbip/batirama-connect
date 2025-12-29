<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * Résultat de transmission d'une commande à un fournisseur.
 */
class SupplierOrderResult
{
    public function __construct(
        /** Commande acceptée par le fournisseur */
        public readonly bool $success,

        /** Référence de commande chez le fournisseur */
        public readonly ?string $supplierOrderRef,

        /** Message d'erreur si échec */
        public readonly ?string $errorMessage = null,

        /** Code d'erreur si échec */
        public readonly ?string $errorCode = null,

        /** Détails par item [sku => ['accepted' => bool, 'quantity' => float, 'price' => float]] */
        public readonly array $itemDetails = [],

        /** Montant total confirmé HT */
        public readonly ?float $confirmedTotalHt = null,

        /** Date de livraison estimée */
        public readonly ?\DateTimeInterface $estimatedDelivery = null,

        /** Métadonnées supplémentaires */
        public readonly array $metadata = [],
    ) {}

    /**
     * Crée un résultat de succès.
     */
    public static function success(
        string $supplierOrderRef,
        array $itemDetails = [],
        ?float $confirmedTotalHt = null,
        ?\DateTimeInterface $estimatedDelivery = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            supplierOrderRef: $supplierOrderRef,
            itemDetails: $itemDetails,
            confirmedTotalHt: $confirmedTotalHt,
            estimatedDelivery: $estimatedDelivery,
            metadata: $metadata
        );
    }

    /**
     * Crée un résultat d'échec.
     */
    public static function failure(
        string $errorMessage,
        ?string $errorCode = null,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            supplierOrderRef: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            metadata: $metadata
        );
    }

    /**
     * Conversion en tableau.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'supplier_order_ref' => $this->supplierOrderRef,
            'error' => $this->success ? null : [
                'message' => $this->errorMessage,
                'code' => $this->errorCode,
            ],
            'item_details' => $this->itemDetails,
            'confirmed_total_ht' => $this->confirmedTotalHt,
            'estimated_delivery' => $this->estimatedDelivery?->format('Y-m-d'),
            'metadata' => $this->metadata,
        ];
    }
}
