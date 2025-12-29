<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * Résultat d'une opération de matching SKU.
 *
 * Contient les items matchés, partiellement matchés et non matchés,
 * ainsi que des statistiques sur le matching.
 */
class MatchResult
{
    public function __construct(
        /** @var array Items avec un match fiable (score >= 80%) */
        public readonly array $matched,

        /** @var array Items avec un match partiel (score entre 40-80%) */
        public readonly array $partial,

        /** @var array Items sans match acceptable */
        public readonly array $unmatched,

        /** @var array Statistiques de matching */
        public readonly array $stats,
    ) {}

    /**
     * Indique si tous les items ont été matchés.
     */
    public function isFullyMatched(): bool
    {
        return empty($this->partial) && empty($this->unmatched);
    }

    /**
     * Indique si le matching nécessite une validation manuelle.
     */
    public function needsManualReview(): bool
    {
        return !empty($this->partial) || !empty($this->unmatched);
    }

    /**
     * Retourne le nombre total d'items traités.
     */
    public function getTotalCount(): int
    {
        return $this->stats['total'] ?? 0;
    }

    /**
     * Retourne le taux de matching (%).
     */
    public function getMatchRate(): float
    {
        return $this->stats['match_rate'] ?? 0.0;
    }

    /**
     * Retourne un résumé textuel du matching.
     */
    public function getSummary(): string
    {
        $total = $this->getTotalCount();
        $matched = count($this->matched);
        $partial = count($this->partial);
        $unmatched = count($this->unmatched);
        $rate = $this->getMatchRate();

        return sprintf(
            '%d/%d produits matchés (%.1f%%) - %d partiels, %d non trouvés',
            $matched,
            $total,
            $rate,
            $partial,
            $unmatched
        );
    }

    /**
     * Calcule le total HT estimé des produits matchés.
     */
    public function getEstimatedTotalHt(): float
    {
        $total = 0.0;

        foreach ($this->matched as $item) {
            $product = $item['product'] ?? [];
            $price = $product['price_ht'] ?? $product['unit_price'] ?? 0;
            $quantity = $item['quantity'] ?? 1;
            $total += $price * $quantity;
        }

        return $total;
    }

    /**
     * Retourne les SKUs des produits matchés.
     */
    public function getMatchedSkus(): array
    {
        return array_map(
            fn($item) => $item['product']['sku'] ?? null,
            $this->matched
        );
    }

    /**
     * Conversion en tableau pour API.
     */
    public function toArray(): array
    {
        return [
            'matched' => array_map(fn($item) => [
                'designation' => $item['designation'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'product' => [
                    'id' => $item['product']['id'] ?? null,
                    'sku' => $item['product']['sku'] ?? null,
                    'name' => $item['product']['name'] ?? $item['product']['label'] ?? null,
                    'price_ht' => $item['product']['price_ht'] ?? $item['product']['unit_price'] ?? null,
                ],
                'score' => $item['score'],
                'match_type' => $item['match_type'],
            ], $this->matched),
            'partial' => array_map(fn($item) => [
                'designation' => $item['designation'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'suggestions' => array_map(fn($s) => [
                    'id' => $s['product']['id'] ?? null,
                    'sku' => $s['product']['sku'] ?? null,
                    'name' => $s['product']['name'] ?? $s['product']['label'] ?? null,
                    'score' => $s['score'],
                ], $item['suggestions'] ?? []),
            ], $this->partial),
            'unmatched' => array_map(fn($item) => [
                'designation' => $item['designation'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'reason' => $item['reason'],
            ], $this->unmatched),
            'stats' => $this->stats,
            'summary' => $this->getSummary(),
            'estimated_total_ht' => $this->getEstimatedTotalHt(),
        ];
    }
}
