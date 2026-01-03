<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportMessage extends Model
{
    use HasFactory;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SupportMessage $message) {
            if (empty($message->uuid)) {
                $message->uuid = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'uuid',
        'session_id',
        'sender_type',
        'agent_id',
        'channel',
        'content',
        'original_content',
        'was_ai_improved',
        'email_metadata',
        'is_read',
        'read_at',
        'learned_at',
        'learned_by',
    ];

    protected $casts = [
        'email_metadata' => 'array',
        'was_ai_improved' => 'boolean',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'learned_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Session IA associée.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    /**
     * Agent de support qui a envoyé le message (si sender_type = 'agent').
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Utilisateur qui a validé l'apprentissage.
     */
    public function learnedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learned_by');
    }

    /**
     * Pièces jointes du message.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Vérifie si c'est un message utilisateur.
     */
    public function isFromUser(): bool
    {
        return $this->sender_type === 'user';
    }

    /**
     * Vérifie si c'est un message d'un agent de support.
     */
    public function isFromAgent(): bool
    {
        return $this->sender_type === 'agent';
    }

    /**
     * Vérifie si c'est un message système.
     */
    public function isSystemMessage(): bool
    {
        return $this->sender_type === 'system';
    }

    /**
     * Vérifie si le message a été appris.
     */
    public function isLearned(): bool
    {
        return $this->learned_at !== null;
    }

    /**
     * Vérifie si le message a été envoyé par email.
     */
    public function isEmail(): bool
    {
        return $this->channel === 'email';
    }

    /**
     * Vérifie si le message a été envoyé par chat.
     */
    public function isChat(): bool
    {
        return $this->channel === 'chat';
    }

    /**
     * Marque le message comme lu.
     */
    public function markAsRead(): self
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return $this;
    }

    /**
     * Retourne le nom de l'expéditeur.
     */
    public function getSenderName(): string
    {
        return match ($this->sender_type) {
            'user' => $this->session?->user?->name ?? $this->session?->user_email ?? 'Visiteur',
            'agent' => $this->agent?->name ?? 'Agent',
            'system' => 'Système',
            default => 'Inconnu',
        };
    }
}
