<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EditorWebhookLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRYING = 'retrying';

    protected $fillable = [
        'uuid',
        'webhook_id',
        'event',
        'payload',
        'http_status',
        'response_body',
        'response_time_ms',
        'status',
        'attempt',
        'error_message',
        'created_at',
        'completed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'http_status' => 'integer',
        'response_time_ms' => 'integer',
        'attempt' => 'integer',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (EditorWebhookLog $log) {
            if (empty($log->uuid)) {
                $log->uuid = (string) Str::uuid();
            }
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(EditorWebhook::class, 'webhook_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // METHODS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Mark log as successful.
     */
    public function markAsSuccess(int $httpStatus, ?string $responseBody, int $responseTimeMs): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'http_status' => $httpStatus,
            'response_body' => $responseBody ? substr($responseBody, 0, 5000) : null,
            'response_time_ms' => $responseTimeMs,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark log as failed.
     */
    public function markAsFailed(string $errorMessage, ?int $httpStatus = null, ?int $responseTimeMs = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTimeMs,
            'error_message' => substr($errorMessage, 0, 1000),
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark log for retry.
     */
    public function markForRetry(): void
    {
        $this->update([
            'status' => self::STATUS_RETRYING,
            'attempt' => $this->attempt + 1,
        ]);
    }

    /**
     * Check if can retry.
     */
    public function canRetry(): bool
    {
        return $this->attempt < $this->webhook->max_retries;
    }

    /**
     * Is successful response.
     */
    public function isSuccess(): bool
    {
        return $this->http_status >= 200 && $this->http_status < 300;
    }
}
