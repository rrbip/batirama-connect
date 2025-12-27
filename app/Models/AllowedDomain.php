<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllowedDomain extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'deployment_id',
        'domain',
        'is_wildcard',
        'environment',
        'is_active',
        'verified_at',
        'created_at',
    ];

    protected $casts = [
        'is_wildcard' => 'boolean',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(AgentDeployment::class, 'deployment_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Vérifie si un host correspond à ce domaine autorisé.
     */
    public function matches(string $host): bool
    {
        // Localhost pour développement
        if ($this->environment === 'localhost' || $this->environment === 'development') {
            if (in_array($host, ['localhost', '127.0.0.1'], true)) {
                return true;
            }
            // localhost:port
            if (str_starts_with($host, 'localhost:') || str_starts_with($host, '127.0.0.1:')) {
                return true;
            }
        }

        if ($this->is_wildcard) {
            // *.example.com → sub.example.com OK
            $pattern = str_replace('*.', '', $this->domain);

            return str_ends_with($host, $pattern) || $host === $pattern;
        }

        return $host === $this->domain;
    }

    /**
     * Vérifie si le domaine a été vérifié (DNS).
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Marque le domaine comme vérifié.
     */
    public function markAsVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }
}
