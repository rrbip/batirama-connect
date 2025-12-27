<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements FilamentUser
{
    use Auditable, HasFactory, Notifiable, SoftDeletes;

    /**
     * Attributes hidden from audit log.
     */
    protected array $hiddenFromAudit = ['password', 'remember_token'];

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'email',
        'password',
        'email_verified_at',
        // Marketplace columns
        'company_name',
        'company_info',
        'branding',
        'marketplace_enabled',
        'api_key',
        'api_key_prefix',
        'max_deployments',
        'max_sessions_month',
        'max_messages_month',
        'current_month_sessions',
        'current_month_messages',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'company_info' => 'array',
            'branding' => 'array',
            'marketplace_enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function aiSessions(): HasMany
    {
        return $this->hasMany(AiSession::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    public function hasPermission(string $permissionSlug): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('slug', $permissionSlug))
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    /**
     * Determine if the user can access the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Vérifier si l'utilisateur a un rôle admin
        if ($this->hasRole('super-admin') || $this->hasRole('admin')) {
            return true;
        }

        // En développement ou si aucun rôle admin n'existe, autoriser l'accès
        if (app()->environment('local', 'development')) {
            return true;
        }

        // Fallback: autoriser si aucun rôle admin n'existe encore (premier setup)
        return !\App\Models\Role::whereIn('slug', ['super-admin', 'admin'])->exists();
    }

    /**
     * Get the user's initials for avatar display.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return null; // Utilise les initiales par défaut
    }

    /**
     * Get the user's name for Filament display.
     */
    public function getFilamentName(): string
    {
        return $this->name;
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS MARKETPLACE
    // ─────────────────────────────────────────────────────────────────

    /**
     * Liens vers les éditeurs (en tant qu'artisan).
     */
    public function editorLinks(): HasMany
    {
        return $this->hasMany(UserEditorLink::class, 'artisan_id');
    }

    /**
     * Artisans liés (en tant qu'éditeur).
     */
    public function linkedArtisans(): HasMany
    {
        return $this->hasMany(UserEditorLink::class, 'editor_id');
    }

    /**
     * Déploiements d'agents (en tant qu'éditeur).
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(AgentDeployment::class, 'editor_id');
    }

    /**
     * Sessions en tant que particulier (client final).
     */
    public function sessionsAsParticulier(): HasMany
    {
        return $this->hasMany(AiSession::class, 'particulier_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES MARKETPLACE - RÔLES
    // ─────────────────────────────────────────────────────────────────

    public function isArtisan(): bool
    {
        return $this->hasRole('artisan');
    }

    public function isEditeur(): bool
    {
        return $this->hasRole('editeur');
    }

    public function isFabricant(): bool
    {
        return $this->hasRole('fabricant');
    }

    public function isParticulier(): bool
    {
        return $this->hasRole('particulier');
    }

    public function isMetreur(): bool
    {
        return $this->hasRole('metreur');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES MARKETPLACE - API KEY
    // ─────────────────────────────────────────────────────────────────

    /**
     * Génère une nouvelle API key pour cet utilisateur.
     */
    public function generateApiKey(?string $prefix = null): string
    {
        $prefix = $prefix ?? $this->getDefaultApiKeyPrefix();
        $key = $prefix . '_' . Str::random(40);

        $this->update([
            'api_key' => $key,
            'api_key_prefix' => $prefix,
        ]);

        return $key;
    }

    /**
     * Retourne le préfixe par défaut selon le rôle.
     */
    protected function getDefaultApiKeyPrefix(): string
    {
        if ($this->isEditeur()) {
            return 'edt';
        }
        if ($this->isFabricant()) {
            return 'fab';
        }
        if ($this->isArtisan()) {
            return 'art';
        }

        return 'usr';
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES MARKETPLACE - QUOTAS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Vérifie si le quota de sessions du mois est atteint.
     */
    public function hasSessionQuotaRemaining(): bool
    {
        if ($this->max_sessions_month === null) {
            return true;
        }

        return $this->current_month_sessions < $this->max_sessions_month;
    }

    /**
     * Vérifie si le quota de messages du mois est atteint.
     */
    public function hasMessageQuotaRemaining(): bool
    {
        if ($this->max_messages_month === null) {
            return true;
        }

        return $this->current_month_messages < $this->max_messages_month;
    }

    /**
     * Vérifie si le quota de déploiements est atteint.
     */
    public function hasDeploymentQuotaRemaining(): bool
    {
        if ($this->max_deployments === null) {
            return true;
        }

        return $this->deployments()->count() < $this->max_deployments;
    }

    /**
     * Incrémente le compteur de sessions du mois.
     */
    public function incrementSessionCount(): void
    {
        $this->increment('current_month_sessions');
    }

    /**
     * Incrémente le compteur de messages du mois.
     */
    public function incrementMessageCount(): void
    {
        $this->increment('current_month_messages');
    }

    /**
     * Réinitialise les compteurs mensuels.
     */
    public function resetMonthlyCounters(): void
    {
        $this->update([
            'current_month_sessions' => 0,
            'current_month_messages' => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES MARKETPLACE - LIAISON ARTISAN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Lie un artisan à cet éditeur.
     */
    public function linkArtisan(User $artisan, string $externalId, array $data = []): UserEditorLink
    {
        return UserEditorLink::create([
            'artisan_id' => $artisan->id,
            'editor_id' => $this->id,
            'external_id' => $externalId,
            'branding' => $data['branding'] ?? null,
            'permissions' => $data['permissions'] ?? null,
            'is_active' => true,
            'linked_at' => now(),
        ]);
    }

    /**
     * Vérifie si un artisan est lié à cet éditeur.
     */
    public function hasLinkedArtisan(User $artisan): bool
    {
        return $this->linkedArtisans()
            ->where('artisan_id', $artisan->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Trouve le lien avec un éditeur par external_id.
     */
    public function findEditorLinkByExternalId(User $editor, string $externalId): ?UserEditorLink
    {
        return UserEditorLink::where('editor_id', $editor->id)
            ->where('external_id', $externalId)
            ->where('is_active', true)
            ->first();
    }
}
