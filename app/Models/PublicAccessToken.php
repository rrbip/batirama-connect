<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicAccessToken extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'token',
        'agent_id',
        'created_by',
        'tenant_id',
        'external_app',
        'external_ref',
        'external_meta',
        'session_id',
        'client_info',
        'expires_at',
        'max_uses',
        'use_count',
        'status',
        'first_used_at',
        'last_used_at',
        'last_ip',
        'last_user_agent',
        'created_at',
    ];

    protected $casts = [
        'external_meta' => 'array',
        'client_info' => 'array',
        'expires_at' => 'datetime',
        'first_used_at' => 'datetime',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    public function isValid(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses && $this->use_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function markAsUsed(string $ip, string $userAgent): void
    {
        $now = now();

        $this->update([
            'use_count' => $this->use_count + 1,
            'first_used_at' => $this->first_used_at ?? $now,
            'last_used_at' => $now,
            'last_ip' => $ip,
            'last_user_agent' => $userAgent,
            'status' => $this->use_count + 1 >= $this->max_uses ? 'used' : 'active',
        ]);
    }

    public function getUrl(): string
    {
        return url("/c/{$this->token}");
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
