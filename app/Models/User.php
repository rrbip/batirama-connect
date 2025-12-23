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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
        // En développement, tous les utilisateurs vérifiés peuvent accéder
        // En production, ajouter une vérification de rôle admin
        if (app()->environment('local', 'development')) {
            return true;
        }

        return $this->hasRole('super-admin') || $this->hasRole('admin');
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
}
