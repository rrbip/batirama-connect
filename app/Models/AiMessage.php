<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiMessage extends Model
{
    use HasFactory;

    // Constantes pour les statuts de traitement
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'session_id',
        'role',
        'content',
        'attachments',
        'rag_context',
        'model_used',
        'tokens_prompt',
        'tokens_completion',
        'generation_time_ms',
        'validation_status',
        'validated_by',
        'validated_at',
        'corrected_content',
        'created_at',
        // Nouveaux champs pour le traitement asynchrone
        'processing_status',
        'queued_at',
        'processing_started_at',
        'processing_completed_at',
        'processing_error',
        'job_id',
        'retry_count',
    ];

    protected $casts = [
        'attachments' => 'array',
        'rag_context' => 'array',
        'validated_at' => 'datetime',
        'created_at' => 'datetime',
        'queued_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    // =========================================
    // Relations
    // =========================================

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(AiFeedback::class, 'message_id');
    }

    // =========================================
    // Scopes pour le filtrage
    // =========================================

    /**
     * Messages en attente de dispatch
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('processing_status', self::STATUS_PENDING);
    }

    /**
     * Messages en file d'attente
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('processing_status', self::STATUS_QUEUED);
    }

    /**
     * Messages en cours de traitement
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('processing_status', self::STATUS_PROCESSING);
    }

    /**
     * Messages terminés avec succès
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('processing_status', self::STATUS_COMPLETED);
    }

    /**
     * Messages en échec
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('processing_status', self::STATUS_FAILED);
    }

    /**
     * Messages dans la queue (pending, queued ou processing)
     */
    public function scopeInQueue(Builder $query): Builder
    {
        return $query->whereIn('processing_status', [
            self::STATUS_PENDING,
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Messages assistant uniquement
     */
    public function scopeAssistantMessages(Builder $query): Builder
    {
        return $query->where('role', 'assistant');
    }

    // =========================================
    // Méthodes de mise à jour du statut
    // =========================================

    /**
     * Marque le message comme mis en file d'attente
     */
    public function markAsQueued(?string $jobId = null): self
    {
        $this->update([
            'processing_status' => self::STATUS_QUEUED,
            'queued_at' => now(),
            'job_id' => $jobId,
        ]);

        return $this;
    }

    /**
     * Marque le message comme en cours de traitement
     */
    public function markAsProcessing(): self
    {
        $this->update([
            'processing_status' => self::STATUS_PROCESSING,
            'processing_started_at' => now(),
        ]);

        return $this;
    }

    /**
     * Marque le message comme terminé avec succès
     */
    public function markAsCompleted(
        string $content,
        ?string $model = null,
        ?int $tokensPrompt = null,
        ?int $tokensCompletion = null,
        ?int $generationTimeMs = null,
        ?array $ragContext = null
    ): self {
        $this->update([
            'processing_status' => self::STATUS_COMPLETED,
            'processing_completed_at' => now(),
            'content' => $content,
            'model_used' => $model,
            'tokens_prompt' => $tokensPrompt,
            'tokens_completion' => $tokensCompletion,
            'generation_time_ms' => $generationTimeMs,
            'rag_context' => $ragContext,
        ]);

        return $this;
    }

    /**
     * Marque le message comme échoué
     */
    public function markAsFailed(string $error, int $attempt = 1): self
    {
        $this->update([
            'processing_status' => self::STATUS_FAILED,
            'processing_completed_at' => now(),
            'processing_error' => $error,
            'retry_count' => $attempt,
        ]);

        return $this;
    }

    /**
     * Réinitialise le message pour un nouveau traitement (retry)
     */
    public function resetForRetry(): self
    {
        $this->update([
            'processing_status' => self::STATUS_PENDING,
            'processing_error' => null,
            'processing_started_at' => null,
            'processing_completed_at' => null,
            'job_id' => null,
        ]);

        return $this;
    }

    // =========================================
    // Méthodes utilitaires
    // =========================================

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function isPending(): bool
    {
        return $this->validation_status === 'pending';
    }

    public function isValidated(): bool
    {
        return $this->validation_status === 'validated';
    }

    /**
     * Vérifie si le message est en cours de traitement async
     */
    public function isBeingProcessed(): bool
    {
        return in_array($this->processing_status, [
            self::STATUS_PENDING,
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Vérifie si le traitement a échoué
     */
    public function hasProcessingFailed(): bool
    {
        return $this->processing_status === self::STATUS_FAILED;
    }

    /**
     * Vérifie si le traitement est terminé (succès ou échec)
     */
    public function isProcessingDone(): bool
    {
        return in_array($this->processing_status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ]);
    }

    /**
     * Calcule le temps d'attente en queue (secondes)
     */
    public function getQueueWaitTimeAttribute(): ?int
    {
        if (!$this->queued_at) {
            return null;
        }

        $endTime = $this->processing_started_at ?? now();
        return (int) $this->queued_at->diffInSeconds($endTime);
    }

    /**
     * Calcule le temps de traitement total (secondes)
     */
    public function getTotalProcessingTimeAttribute(): ?int
    {
        if (!$this->processing_started_at || !$this->processing_completed_at) {
            return null;
        }

        return (int) $this->processing_started_at->diffInSeconds($this->processing_completed_at);
    }
}
