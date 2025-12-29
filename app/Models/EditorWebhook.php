<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EditorWebhook extends Model
{
    use HasFactory;

    // Available webhook events
    public const EVENT_SESSION_STARTED = 'session.started';
    public const EVENT_SESSION_COMPLETED = 'session.completed';
    public const EVENT_MESSAGE_RECEIVED = 'message.received';
    public const EVENT_FILE_UPLOADED = 'file.uploaded';
    public const EVENT_PROJECT_CREATED = 'project.created';
    public const EVENT_LEAD_GENERATED = 'lead.generated';

    public const EVENTS = [
        self::EVENT_SESSION_STARTED,
        self::EVENT_SESSION_COMPLETED,
        self::EVENT_MESSAGE_RECEIVED,
        self::EVENT_FILE_UPLOADED,
        self::EVENT_PROJECT_CREATED,
        self::EVENT_LEAD_GENERATED,
    ];

    protected $fillable = [
        'uuid',
        'editor_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'max_retries',
        'timeout_ms',
        'last_triggered_at',
        'last_status',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'max_retries' => 'integer',
        'timeout_ms' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'last_triggered_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (EditorWebhook $webhook) {
            if (empty($webhook->uuid)) {
                $webhook->uuid = (string) Str::uuid();
            }
            if (empty($webhook->secret)) {
                $webhook->secret = Str::random(64);
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EditorWebhookLog::class, 'webhook_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // METHODS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Check if webhook should trigger for a specific event.
     */
    public function shouldTrigger(string $event): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $events = $this->events ?? [];

        return in_array($event, $events, true) || in_array('*', $events, true);
    }

    /**
     * Generate HMAC signature for payload.
     */
    public function generateSignature(array $payload): string
    {
        $json = json_encode($payload);

        return 'sha256=' . hash_hmac('sha256', $json, $this->secret);
    }

    /**
     * Record successful delivery.
     */
    public function recordSuccess(): void
    {
        $this->increment('success_count');
        $this->update([
            'last_triggered_at' => now(),
            'last_status' => 'success',
        ]);
    }

    /**
     * Record failed delivery.
     */
    public function recordFailure(): void
    {
        $this->increment('failure_count');
        $this->update([
            'last_triggered_at' => now(),
            'last_status' => 'failed',
        ]);
    }

    /**
     * Get recent logs.
     */
    public function recentLogs(int $limit = 10)
    {
        return $this->logs()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get available events with descriptions.
     */
    public static function getEventDescriptions(): array
    {
        return [
            self::EVENT_SESSION_STARTED => 'Session démarrée',
            self::EVENT_SESSION_COMPLETED => 'Session terminée',
            self::EVENT_MESSAGE_RECEIVED => 'Message reçu (réponse IA)',
            self::EVENT_FILE_UPLOADED => 'Fichier uploadé',
            self::EVENT_PROJECT_CREATED => 'Projet/devis créé',
            self::EVENT_LEAD_GENERATED => 'Lead généré',
        ];
    }
}
