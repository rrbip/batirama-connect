<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Log des actions de validation sur les sessions.
 *
 * Trace l'historique complet du workflow de validation:
 * soumission → validation client → validation master → promotion
 */
class SessionValidationLog extends Model
{
    // Actions possibles
    public const ACTION_SUBMITTED = 'submitted';
    public const ACTION_CLIENT_VALIDATED = 'client_validated';
    public const ACTION_CLIENT_REJECTED = 'client_rejected';
    public const ACTION_MASTER_VALIDATED = 'master_validated';
    public const ACTION_MASTER_REJECTED = 'master_rejected';
    public const ACTION_PROMOTED = 'promoted';
    public const ACTION_MODIFICATION_REQUESTED = 'modification_requested';

    protected $fillable = [
        'uuid',
        'session_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SessionValidationLog $log) {
            if (empty($log->uuid)) {
                $log->uuid = (string) Str::uuid();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─────────────────────────────────────────────────────────────────
    // ACCESSEURS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Libellé de l'action en français.
     */
    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_SUBMITTED => 'Session soumise pour validation',
            self::ACTION_CLIENT_VALIDATED => 'Validé par le client',
            self::ACTION_CLIENT_REJECTED => 'Rejeté par le client',
            self::ACTION_MASTER_VALIDATED => 'Validé par le master',
            self::ACTION_MASTER_REJECTED => 'Rejeté par le master',
            self::ACTION_PROMOTED => 'Promu en réponse apprise',
            self::ACTION_MODIFICATION_REQUESTED => 'Modification demandée',
            default => $this->action,
        };
    }

    /**
     * Couleur associée à l'action.
     */
    public function getActionColorAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_SUBMITTED => 'info',
            self::ACTION_CLIENT_VALIDATED, self::ACTION_MASTER_VALIDATED => 'success',
            self::ACTION_CLIENT_REJECTED, self::ACTION_MASTER_REJECTED => 'danger',
            self::ACTION_PROMOTED => 'primary',
            self::ACTION_MODIFICATION_REQUESTED => 'warning',
            default => 'gray',
        };
    }

    /**
     * Icône associée à l'action.
     */
    public function getActionIconAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_SUBMITTED => 'heroicon-o-paper-airplane',
            self::ACTION_CLIENT_VALIDATED, self::ACTION_MASTER_VALIDATED => 'heroicon-o-check-circle',
            self::ACTION_CLIENT_REJECTED, self::ACTION_MASTER_REJECTED => 'heroicon-o-x-circle',
            self::ACTION_PROMOTED => 'heroicon-o-star',
            self::ACTION_MODIFICATION_REQUESTED => 'heroicon-o-pencil',
            default => 'heroicon-o-information-circle',
        };
    }

    // ─────────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Filtre par action.
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Filtre par session.
     */
    public function scopeForSession($query, AiSession $session)
    {
        return $query->where('session_id', $session->id);
    }

    /**
     * Logs récents en premier.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
