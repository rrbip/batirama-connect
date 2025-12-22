<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'agent_id',
        'user_id',
        'tenant_id',
        'partner_id',
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
}
