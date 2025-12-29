<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * Résultat du dispatch d'une commande vers les fournisseurs.
 */
class DispatchResult
{
    public function __construct(
        /** Au moins un fournisseur a accepté */
        public readonly bool $success,

        /** Nombre total de fournisseurs ciblés */
        public readonly int $totalSuppliers,

        /** Nombre de fournisseurs ayant accepté */
        public readonly int $successCount,

        /** Nombre de fournisseurs ayant refusé/échoué */
        public readonly int $failureCount,

        /** Résultats par fournisseur */
        public readonly array $supplierResults = [],

        /** Message d'erreur global si échec total */
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * Crée un résultat d'échec total.
     */
    public static function failure(string $errorMessage): self
    {
        return new self(
            success: false,
            totalSuppliers: 0,
            successCount: 0,
            failureCount: 0,
            errorMessage: $errorMessage
        );
    }

    /**
     * Indique si tous les fournisseurs ont accepté.
     */
    public function isFullSuccess(): bool
    {
        return $this->success && $this->failureCount === 0;
    }

    /**
     * Indique si la commande est partiellement dispatchée.
     */
    public function isPartialSuccess(): bool
    {
        return $this->success && $this->failureCount > 0;
    }

    /**
     * Résumé textuel.
     */
    public function getSummary(): string
    {
        if (!$this->success) {
            return $this->errorMessage ?? 'Échec du dispatch';
        }

        if ($this->isFullSuccess()) {
            return sprintf(
                'Commande transmise à %d fournisseur(s)',
                $this->successCount
            );
        }

        return sprintf(
            'Commande partiellement transmise: %d/%d fournisseur(s)',
            $this->successCount,
            $this->totalSuppliers
        );
    }

    /**
     * Conversion en tableau.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'total_suppliers' => $this->totalSuppliers,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'summary' => $this->getSummary(),
            'error_message' => $this->errorMessage,
            'supplier_results' => array_map(
                fn (SupplierOrderResult $r) => $r->toArray(),
                $this->supplierResults
            ),
        ];
    }
}
