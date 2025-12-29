<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Expédition d'une commande marketplace.
 *
 * Permet de suivre les livraisons de matériaux commandés
 * suite à un devis validé.
 */
class MarketplaceShipment extends Model
{
    // Statuts d'expédition
    public const STATUS_PENDING = 'pending';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'order_id',
        'supplier_id',
        'supplier_name',
        'supplier_order_ref',
        'carrier_name',
        'carrier_tracking_number',
        'carrier_tracking_url',
        'status',
        'estimated_delivery_at',
        'shipped_at',
        'delivered_at',
        'metadata',
    ];

    protected $casts = [
        'estimated_delivery_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MarketplaceShipment $shipment) {
            if (empty($shipment->uuid)) {
                $shipment->uuid = (string) Str::uuid();
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
     * Libellé du statut.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'En attente',
            self::STATUS_PREPARING => 'En préparation',
            self::STATUS_SHIPPED => 'Expédié',
            self::STATUS_IN_TRANSIT => 'En transit',
            self::STATUS_DELIVERED => 'Livré',
            self::STATUS_FAILED => 'Échec de livraison',
            default => $this->status,
        };
    }

    /**
     * Couleur du statut.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'gray',
            self::STATUS_PREPARING => 'info',
            self::STATUS_SHIPPED => 'primary',
            self::STATUS_IN_TRANSIT => 'warning',
            self::STATUS_DELIVERED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    /**
     * A un numéro de suivi?
     */
    public function getHasTrackingAttribute(): bool
    {
        return !empty($this->carrier_tracking_number);
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Marque comme expédié.
     */
    public function markAsShipped(
        string $carrierName,
        ?string $trackingNumber = null,
        ?string $trackingUrl = null
    ): void {
        $this->update([
            'status' => self::STATUS_SHIPPED,
            'carrier_name' => $carrierName,
            'carrier_tracking_number' => $trackingNumber,
            'carrier_tracking_url' => $trackingUrl,
            'shipped_at' => now(),
        ]);
    }

    /**
     * Marque comme livré.
     */
    public function markAsDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    /**
     * Format pour API.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->uuid,
            'supplier_name' => $this->supplier_name,
            'supplier_order_ref' => $this->supplier_order_ref,
            'carrier' => [
                'name' => $this->carrier_name,
                'tracking_number' => $this->carrier_tracking_number,
                'tracking_url' => $this->carrier_tracking_url,
            ],
            'status' => $this->status,
            'status_label' => $this->status_label,
            'estimated_delivery_at' => $this->estimated_delivery_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
