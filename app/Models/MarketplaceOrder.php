<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Commande marketplace générée depuis un pré-devis whitelabel.
 *
 * Permet aux artisans de commander des matériaux directement
 * depuis les estimations générées par l'IA.
 */
class MarketplaceOrder extends Model
{
    // Statuts de commande
    public const STATUS_PENDING_VALIDATION = 'pending_validation';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'session_id',
        'artisan_id',
        'editor_id',
        'quote_reference',
        'status',
        'subtotal_ht',
        'tva_amount',
        'shipping_ht',
        'total_ttc',
        'tva_rate',
        'delivery_address',
        'artisan_notes',
        'internal_notes',
        'metadata',
        'validated_at',
        'ordered_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected $casts = [
        'subtotal_ht' => 'decimal:2',
        'tva_amount' => 'decimal:2',
        'shipping_ht' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'tva_rate' => 'decimal:2',
        'delivery_address' => 'array',
        'metadata' => 'array',
        'validated_at' => 'datetime',
        'ordered_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MarketplaceOrder $order) {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceOrderItem::class, 'order_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(MarketplaceShipment::class, 'order_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // ACCESSEURS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Libellé du statut.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_VALIDATION => 'En attente de validation',
            self::STATUS_VALIDATED => 'Validée',
            self::STATUS_PROCESSING => 'En cours de traitement',
            self::STATUS_ORDERED => 'Commandée',
            self::STATUS_SHIPPED => 'Expédiée',
            self::STATUS_DELIVERED => 'Livrée',
            self::STATUS_CANCELLED => 'Annulée',
            default => $this->status,
        };
    }

    /**
     * Couleur du statut pour l'affichage.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_VALIDATION => 'warning',
            self::STATUS_VALIDATED => 'info',
            self::STATUS_PROCESSING => 'primary',
            self::STATUS_ORDERED => 'success',
            self::STATUS_SHIPPED => 'success',
            self::STATUS_DELIVERED => 'success',
            self::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }

    /**
     * Nombre de produits matchés.
     */
    public function getMatchedItemsCountAttribute(): int
    {
        return $this->items()->where('match_status', 'matched')->count();
    }

    /**
     * Nombre de produits non trouvés.
     */
    public function getUnmatchedItemsCountAttribute(): int
    {
        return $this->items()->where('match_status', 'not_found')->count();
    }

    /**
     * Total HT (alias de subtotal_ht).
     */
    public function getTotalHtAttribute(): float
    {
        return (float) $this->subtotal_ht;
    }

    /**
     * Adresse de livraison formatée.
     */
    public function getFormattedAddressAttribute(): string
    {
        $addr = $this->delivery_address ?? [];

        $lines = array_filter([
            $addr['name'] ?? null,
            $addr['company'] ?? null,
            $addr['line1'] ?? null,
            $addr['line2'] ?? null,
            trim(($addr['postal_code'] ?? '') . ' ' . ($addr['city'] ?? '')),
            $addr['country'] ?? 'France',
        ]);

        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Peut être validée par l'artisan?
     */
    public function canBeValidated(): bool
    {
        return $this->status === self::STATUS_PENDING_VALIDATION;
    }

    /**
     * Peut être annulée?
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_VALIDATION,
            self::STATUS_VALIDATED,
        ], true);
    }

    /**
     * Valide la commande.
     */
    public function validate(): void
    {
        if (!$this->canBeValidated()) {
            throw new \InvalidArgumentException('Cette commande ne peut pas être validée');
        }

        $this->update([
            'status' => self::STATUS_VALIDATED,
            'validated_at' => now(),
        ]);
    }

    /**
     * Annule la commande.
     */
    public function cancel(string $reason = null): void
    {
        if (!$this->canBeCancelled()) {
            throw new \InvalidArgumentException('Cette commande ne peut pas être annulée');
        }

        $metadata = $this->metadata ?? [];
        $metadata['cancellation_reason'] = $reason;

        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Recalcule les totaux.
     */
    public function recalculateTotals(): void
    {
        $subtotal = $this->items()
            ->where('line_status', 'included')
            ->sum('line_total_ht');

        $tvaAmount = $subtotal * ($this->tva_rate / 100);
        $totalTtc = $subtotal + $tvaAmount + ($this->shipping_ht ?? 0);

        $this->update([
            'subtotal_ht' => $subtotal,
            'tva_amount' => $tvaAmount,
            'total_ttc' => $totalTtc,
        ]);
    }

    /**
     * Crée une commande depuis un pré-devis.
     */
    public static function createFromPreQuote(
        AiSession $session,
        array $preQuote,
        User $artisan,
        ?string $quoteReference = null,
        ?array $deliveryAddress = null
    ): self {
        $order = self::create([
            'session_id' => $session->id,
            'artisan_id' => $artisan->id,
            'editor_id' => $session->deployment?->editor_id,
            'quote_reference' => $quoteReference,
            'status' => self::STATUS_PENDING_VALIDATION,
            'tva_rate' => $preQuote['tva_rate'] ?? 20,
            'delivery_address' => $deliveryAddress,
            'metadata' => [
                'source' => 'pre_quote',
                'project_type' => $preQuote['project_type'] ?? null,
            ],
        ]);

        // Créer les lignes depuis les items du pré-devis
        foreach ($preQuote['items'] ?? [] as $item) {
            MarketplaceOrderItem::create([
                'order_id' => $order->id,
                'original_designation' => $item['designation'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'] ?? 'u',
                'match_status' => 'not_found', // À matcher via SkuMatchingService
            ]);
        }

        return $order;
    }

    /**
     * Format pour API.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->uuid,
            'quote_reference' => $this->quote_reference,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'items_count' => $this->items()->count(),
            'matched_items_count' => $this->matched_items_count,
            'subtotal_ht' => (float) $this->subtotal_ht,
            'tva_rate' => (float) $this->tva_rate,
            'total_ttc' => (float) $this->total_ttc,
            'delivery_address' => $this->delivery_address,
            'created_at' => $this->created_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
        ];
    }
}
