<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class AiSession extends Model
{
    use HasFactory;

    /**
     * Boot the model - Auto-generate UUID on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (AiSession $session) {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'uuid',
        'agent_id',
        'user_id',
        'tenant_id',
        'partner_id',
        // Whitelabel columns
        'editor_link_id',
        'deployment_id',
        'particulier_id',
        'whitelabel_token',
        // Standard columns
        'external_session_id',
        'external_ref',
        'external_context',
        'title',
        'message_count',
        'status',
        'started_at',
        'ended_at',
        'last_activity_at',
        'closed_at',
        'client_data',
        'metadata',
        'is_marketplace_lead',
        'conversion_status',
        'conversion_amount',
        'final_amount',
        'quote_ref',
        'signed_at',
        'conversion_notes',
        'conversion_at',
        'commission_rate',
        'commission_amount',
        'commission_status',
    ];

    protected $casts = [
        'external_context' => 'array',
        'client_data' => 'array',
        'metadata' => 'array',
        'is_marketplace_lead' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'closed_at' => 'datetime',
        'signed_at' => 'datetime',
        'conversion_at' => 'datetime',
        'conversion_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'session_id');
    }

    public function publicAccessToken(): HasOne
    {
        return $this->hasOne(PublicAccessToken::class, 'session_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function close(): void
    {
        $this->update([
            'status' => 'archived',
            'closed_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS WHITELABEL
    // ─────────────────────────────────────────────────────────────────

    /**
     * Le lien artisan ↔ éditeur utilisé pour cette session.
     */
    public function editorLink(): BelongsTo
    {
        return $this->belongsTo(UserEditorLink::class, 'editor_link_id');
    }

    /**
     * Le déploiement utilisé pour cette session.
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(AgentDeployment::class, 'deployment_id');
    }

    /**
     * Le particulier (client final) de cette session.
     */
    public function particulier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'particulier_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES WHITELABEL
    // ─────────────────────────────────────────────────────────────────

    /**
     * Vérifie si cette session est via un éditeur whitelabel.
     */
    public function isWhitelabelSession(): bool
    {
        return $this->deployment_id !== null;
    }

    /**
     * Retourne l'artisan lié à cette session (via user_id ou editor_link).
     */
    public function getArtisan(): ?User
    {
        if ($this->editor_link_id) {
            return $this->editorLink?->artisan;
        }

        return $this->user;
    }

    /**
     * Retourne l'éditeur lié à cette session.
     */
    public function getEditor(): ?User
    {
        if ($this->editor_link_id) {
            return $this->editorLink?->editor;
        }

        return $this->deployment?->editor;
    }

    /**
     * Résout le branding pour cette session.
     */
    public function resolveBranding(): array
    {
        // 1. Base : Agent par défaut
        $branding = $this->deployment?->agent?->whitelabel_config['default_branding'] ?? [];

        // 2. Override : Deployment
        $branding = array_merge($branding, $this->deployment?->branding ?? []);

        // 3. Override : Artisan
        $artisan = $this->getArtisan();
        $branding = array_merge($branding, $artisan?->branding ?? []);

        // 4. Override final : Lien artisan ↔ éditeur
        if ($this->editor_link_id) {
            $branding = array_merge($branding, $this->editorLink?->branding ?? []);
        }

        return $branding;
    }
}
