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
        'external_context',
        'title',
        'message_count',
        'status',
        'closed_at',
        'conversion_status',
        'conversion_amount',
        'conversion_at',
        'commission_rate',
        'commission_amount',
        'commission_status',
    ];

    protected $casts = [
        'external_context' => 'array',
        'closed_at' => 'datetime',
        'conversion_at' => 'datetime',
        'conversion_amount' => 'decimal:2',
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
