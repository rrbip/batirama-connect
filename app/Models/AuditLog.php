<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_email',
        'ip_address',
        'user_agent',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Log an action.
     */
    public static function log(
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        $user = auth()->user();
        $request = request();

        return self::create([
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'created_at' => now(),
        ]);
    }

    /**
     * Get the action badge color.
     */
    public function getActionColor(): string
    {
        return match ($this->action) {
            'create' => 'success',
            'update' => 'info',
            'delete' => 'danger',
            'restore' => 'warning',
            'login' => 'primary',
            'logout' => 'gray',
            default => 'secondary',
        };
    }

    /**
     * Get the action label in French.
     */
    public function getActionLabel(): string
    {
        return match ($this->action) {
            'create' => 'Création',
            'update' => 'Modification',
            'delete' => 'Suppression',
            'restore' => 'Restauration',
            'login' => 'Connexion',
            'logout' => 'Déconnexion',
            'export' => 'Export',
            default => ucfirst($this->action),
        };
    }

    /**
     * Get the auditable model name in French.
     */
    public function getAuditableLabel(): string
    {
        if (! $this->auditable_type) {
            return '-';
        }

        $modelName = class_basename($this->auditable_type);

        return match ($modelName) {
            'User' => 'Utilisateur',
            'Role' => 'Rôle',
            'Permission' => 'Permission',
            'Agent' => 'Agent IA',
            'AiSession' => 'Session IA',
            'Ouvrage' => 'Ouvrage',
            'Document' => 'Document',
            'Partner' => 'Partenaire',
            default => $modelName,
        };
    }
}
