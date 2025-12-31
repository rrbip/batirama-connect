<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;

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
        // Support/Handoff columns
        'support_status',
        'escalation_reason',
        'escalated_at',
        'support_agent_id',
        'assigned_at',
        'user_email',
        'resolved_at',
        'resolution_type',
        'resolution_notes',
        'support_access_token',
        'support_token_expires_at',
        'support_metadata',
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
        // Support casts
        'escalated_at' => 'datetime',
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'support_token_expires_at' => 'datetime',
        'support_metadata' => 'array',
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

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS SUPPORT HUMAIN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Agent de support assigné à cette session.
     */
    public function supportAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'support_agent_id');
    }

    /**
     * Messages de support (chat/email avec l'agent humain).
     */
    public function supportMessages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'session_id');
    }

    /**
     * Pièces jointes de support.
     */
    public function supportAttachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'session_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES SUPPORT HUMAIN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Vérifie si cette session a été escaladée.
     */
    public function isEscalated(): bool
    {
        return $this->support_status !== null;
    }

    /**
     * Vérifie si la session est en attente d'un agent.
     */
    public function isAwaitingSupport(): bool
    {
        return $this->support_status === 'escalated';
    }

    /**
     * Vérifie si un agent a pris en charge la session.
     */
    public function isBeingHandled(): bool
    {
        return $this->support_status === 'assigned';
    }

    /**
     * Vérifie si la session est résolue.
     */
    public function isResolved(): bool
    {
        return $this->support_status === 'resolved';
    }

    /**
     * Escalade la session vers le support humain.
     */
    public function escalate(string $reason, ?float $maxRagScore = null): self
    {
        $this->update([
            'support_status' => 'escalated',
            'escalation_reason' => $reason,
            'escalated_at' => now(),
            'support_metadata' => array_merge($this->support_metadata ?? [], [
                'max_rag_score' => $maxRagScore,
                'escalated_by' => 'system',
            ]),
        ]);

        return $this;
    }

    /**
     * Assigne un agent de support à cette session.
     */
    public function assignToAgent(User $agent): self
    {
        $this->update([
            'support_status' => 'assigned',
            'support_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        return $this;
    }

    /**
     * Marque la session comme résolue.
     */
    public function resolve(string $type, ?string $notes = null): self
    {
        $this->update([
            'support_status' => 'resolved',
            'resolved_at' => now(),
            'resolution_type' => $type,
            'resolution_notes' => $notes,
        ]);

        return $this;
    }

    /**
     * Génère un token d'accès pour les réponses par email.
     */
    public function generateSupportAccessToken(int $expiresInHours = 72): string
    {
        $token = Str::random(64);

        $this->update([
            'support_access_token' => $token,
            'support_token_expires_at' => now()->addHours($expiresInHours),
        ]);

        return $token;
    }

    /**
     * Vérifie si le token d'accès est valide.
     */
    public function isAccessTokenValid(string $token): bool
    {
        return $this->support_access_token === $token
            && $this->support_token_expires_at
            && $this->support_token_expires_at->isFuture();
    }

    // ─────────────────────────────────────────────────────────────────
    // SCOPES SUPPORT HUMAIN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sessions escaladées en attente d'un agent.
     */
    public function scopeAwaitingSupport(Builder $query): Builder
    {
        return $query->where('support_status', 'escalated');
    }

    /**
     * Sessions assignées à un agent spécifique.
     */
    public function scopeAssignedTo(Builder $query, User $agent): Builder
    {
        return $query->where('support_agent_id', $agent->id)
            ->where('support_status', 'assigned');
    }

    /**
     * Sessions de support non résolues.
     */
    public function scopeUnresolvedSupport(Builder $query): Builder
    {
        return $query->whereIn('support_status', ['escalated', 'assigned']);
    }

    /**
     * Sessions de support pour un agent IA spécifique.
     */
    public function scopeForAgentSupport(Builder $query, int $agentId): Builder
    {
        return $query->where('agent_id', $agentId)
            ->whereNotNull('support_status');
    }
}
