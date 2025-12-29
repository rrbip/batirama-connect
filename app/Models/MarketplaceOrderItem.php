<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ligne de commande marketplace.
 *
 * Représente un item du pré-devis converti en ligne de commande,
 * avec le matching vers un produit du catalogue marketplace.
 */
class MarketplaceOrderItem extends Model
{
    // Statuts de matching
    public const MATCH_STATUS_MATCHED = 'matched';
    public const MATCH_STATUS_PARTIAL = 'partial_match';
    public const MATCH_STATUS_NOT_FOUND = 'not_found';
    public const MATCH_STATUS_MANUAL = 'manual';

    // Statuts de ligne
    public const LINE_STATUS_PENDING = 'pending';
    public const LINE_STATUS_INCLUDED = 'included';
    public const LINE_STATUS_EXCLUDED = 'excluded';
    public const LINE_STATUS_SUBSTITUTED = 'substituted';

    protected $fillable = [
        'uuid',
        'order_id',
        'original_designation',
        'product_id',
        'product_sku',
        'product_name',
        'match_status',
        'match_score',
        'quantity',
        'unit',
        'quantity_ordered',
        'unit_price_ht',
        'line_total_ht',
        'line_status',
        'substitution_product_id',
        'substitution_reason',
        'metadata',
    ];

    protected $casts = [
        'match_score' => 'decimal:2',
        'quantity' => 'decimal:2',
        'quantity_ordered' => 'decimal:2',
        'unit_price_ht' => 'decimal:2',
        'line_total_ht' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MarketplaceOrderItem $item) {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (MarketplaceOrderItem $item) {
            // Recalculer le total de la ligne
            if ($item->unit_price_ht && $item->quantity_ordered) {
                $item->line_total_ht = $item->unit_price_ht * $item->quantity_ordered;
            } elseif ($item->unit_price_ht && $item->quantity) {
                $item->line_total_ht = $item->unit_price_ht * $item->quantity;
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'order_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // ACCESSEURS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Libellé du statut de matching.
     */
    public function getMatchStatusLabelAttribute(): string
    {
        return match ($this->match_status) {
            self::MATCH_STATUS_MATCHED => 'Produit trouvé',
            self::MATCH_STATUS_PARTIAL => 'Correspondance partielle',
            self::MATCH_STATUS_NOT_FOUND => 'Non trouvé',
            self::MATCH_STATUS_MANUAL => 'Sélection manuelle',
            default => $this->match_status,
        };
    }

    /**
     * Couleur du statut de matching.
     */
    public function getMatchStatusColorAttribute(): string
    {
        return match ($this->match_status) {
            self::MATCH_STATUS_MATCHED => 'success',
            self::MATCH_STATUS_PARTIAL => 'warning',
            self::MATCH_STATUS_NOT_FOUND => 'danger',
            self::MATCH_STATUS_MANUAL => 'info',
            default => 'gray',
        };
    }

    /**
     * Libellé du statut de ligne.
     */
    public function getLineStatusLabelAttribute(): string
    {
        return match ($this->line_status) {
            self::LINE_STATUS_PENDING => 'En attente',
            self::LINE_STATUS_INCLUDED => 'Inclus',
            self::LINE_STATUS_EXCLUDED => 'Exclu',
            self::LINE_STATUS_SUBSTITUTED => 'Substitué',
            default => $this->line_status,
        };
    }

    /**
     * Est matché avec un produit?
     */
    public function getIsMatchedAttribute(): bool
    {
        return in_array($this->match_status, [
            self::MATCH_STATUS_MATCHED,
            self::MATCH_STATUS_MANUAL,
        ], true);
    }

    /**
     * Quantité effective à commander.
     */
    public function getEffectiveQuantityAttribute(): float
    {
        return (float) ($this->quantity_ordered ?? $this->quantity);
    }

    /**
     * Nom du produit à afficher.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->product_name ?? $this->original_designation;
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Assigne un produit matché.
     */
    public function assignProduct(
        int $productId,
        string $sku,
        string $name,
        float $unitPrice,
        float $matchScore = 100
    ): void {
        $this->update([
            'product_id' => $productId,
            'product_sku' => $sku,
            'product_name' => $name,
            'unit_price_ht' => $unitPrice,
            'match_status' => $matchScore >= 80 ? self::MATCH_STATUS_MATCHED : self::MATCH_STATUS_PARTIAL,
            'match_score' => $matchScore,
            'line_status' => self::LINE_STATUS_INCLUDED,
        ]);
    }

    /**
     * Sélection manuelle d'un produit.
     */
    public function selectProduct(
        int $productId,
        string $sku,
        string $name,
        float $unitPrice
    ): void {
        $this->update([
            'product_id' => $productId,
            'product_sku' => $sku,
            'product_name' => $name,
            'unit_price_ht' => $unitPrice,
            'match_status' => self::MATCH_STATUS_MANUAL,
            'match_score' => null,
            'line_status' => self::LINE_STATUS_INCLUDED,
        ]);
    }

    /**
     * Exclure cette ligne de la commande.
     */
    public function exclude(): void
    {
        $this->update(['line_status' => self::LINE_STATUS_EXCLUDED]);
    }

    /**
     * Inclure cette ligne dans la commande.
     */
    public function include(): void
    {
        $this->update(['line_status' => self::LINE_STATUS_INCLUDED]);
    }

    /**
     * Modifier la quantité à commander.
     */
    public function updateQuantity(float $quantity): void
    {
        $this->update([
            'quantity_ordered' => $quantity,
            'line_total_ht' => $this->unit_price_ht * $quantity,
        ]);
    }

    /**
     * Format pour API.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->uuid,
            'original_designation' => $this->original_designation,
            'product' => $this->is_matched ? [
                'id' => $this->product_id,
                'sku' => $this->product_sku,
                'name' => $this->product_name,
                'unit_price_ht' => (float) $this->unit_price_ht,
            ] : null,
            'match_status' => $this->match_status,
            'match_score' => $this->match_score ? (float) $this->match_score : null,
            'quantity' => (float) $this->quantity,
            'quantity_ordered' => $this->quantity_ordered ? (float) $this->quantity_ordered : null,
            'unit' => $this->unit,
            'line_total_ht' => $this->line_total_ht ? (float) $this->line_total_ht : null,
            'line_status' => $this->line_status,
        ];
    }
}
