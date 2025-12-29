<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserEditorLink extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'artisan_id',
        'editor_id',
        'external_id',
        'branding',
        'permissions',
        'is_active',
        'linked_at',
    ];

    protected $casts = [
        'branding' => 'array',
        'permissions' => 'array',
        'is_active' => 'boolean',
        'linked_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    /**
     * L'artisan lié.
     */
    public function artisan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }

    /**
     * L'éditeur (ex: EBP).
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    /**
     * Les sessions créées via ce lien.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(AiSession::class, 'editor_link_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Vérifie si l'artisan a une permission spécifique chez cet éditeur.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return $permissions[$permission] ?? true; // Par défaut autorisé
    }

    /**
     * Retourne la limite de sessions par mois pour cet artisan chez cet éditeur.
     */
    public function getMaxSessionsMonth(): ?int
    {
        return $this->permissions['max_sessions_month'] ?? null;
    }

    /**
     * Vérifie si le quota de sessions du mois est atteint.
     */
    public function hasSessionQuotaRemaining(): bool
    {
        $max = $this->getMaxSessionsMonth();

        if ($max === null) {
            return true;
        }

        $currentMonthSessions = $this->sessions()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        return $currentMonthSessions < $max;
    }

    /**
     * Résout le branding en cascade (artisan → lien éditeur).
     */
    public function resolveBranding(): array
    {
        $artisanBranding = $this->artisan->branding ?? [];
        $linkBranding = $this->branding ?? [];

        return array_merge($artisanBranding, $linkBranding);
    }
}
