<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\LearnedResponseObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

#[ObservedBy([LearnedResponseObserver::class])]
class LearnedResponse extends Model
{
    use HasFactory;

    // Sources possibles
    public const SOURCE_AI_VALIDATION = 'ai_validation';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'qdrant_point_id',
        'agent_id',
        'question',
        'answer',
        'validation_count',
        'rejection_count',
        'requires_handoff',
        'source',
        'source_message_id',
        'created_by',
        'last_validated_by',
        'last_validated_at',
    ];

    protected $casts = [
        'requires_handoff' => 'boolean',
        'validation_count' => 'integer',
        'rejection_count' => 'integer',
        'last_validated_at' => 'datetime',
    ];

    // =========================================
    // Relations
    // =========================================

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'source_message_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastValidator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_validated_by');
    }

    // =========================================
    // Scopes
    // =========================================

    /**
     * Réponses pour un agent spécifique
     */
    public function scopeForAgent(Builder $query, int|Agent $agent): Builder
    {
        $agentId = $agent instanceof Agent ? $agent->id : $agent;
        return $query->where('agent_id', $agentId);
    }

    /**
     * Réponses avec plus de validations que de rejets
     */
    public function scopeTrusted(Builder $query): Builder
    {
        return $query->whereColumn('validation_count', '>', 'rejection_count');
    }

    /**
     * Réponses problématiques (beaucoup de rejets)
     */
    public function scopeProblematic(Builder $query, int $minRejections = 3): Builder
    {
        return $query->where('rejection_count', '>=', $minRejections);
    }

    /**
     * Réponses nécessitant un suivi humain
     */
    public function scopeRequiresHandoff(Builder $query): Builder
    {
        return $query->where('requires_handoff', true);
    }

    // =========================================
    // Méthodes métier
    // =========================================

    /**
     * Incrémente le compteur de validation
     */
    public function incrementValidation(int $userId): self
    {
        $this->increment('validation_count');
        $this->update([
            'last_validated_by' => $userId,
            'last_validated_at' => now(),
        ]);

        return $this;
    }

    /**
     * Incrémente le compteur de rejet
     */
    public function incrementRejection(): self
    {
        $this->increment('rejection_count');
        return $this;
    }

    /**
     * Score de confiance (ratio validation/total)
     */
    public function getConfidenceScoreAttribute(): float
    {
        $total = $this->validation_count + $this->rejection_count;
        if ($total === 0) {
            return 0;
        }
        return $this->validation_count / $total;
    }

    /**
     * Vérifie si la réponse est fiable (plus de validations que de rejets)
     */
    public function isTrusted(): bool
    {
        return $this->validation_count > $this->rejection_count;
    }

    /**
     * Vérifie si la réponse est problématique
     */
    public function isProblematic(): bool
    {
        return $this->rejection_count >= 3 || $this->confidence_score < 0.5;
    }

    /**
     * Données pour l'indexation Qdrant
     */
    public function toQdrantPayload(): array
    {
        return [
            'learned_response_id' => $this->id,
            'agent_id' => $this->agent_id,
            'agent_slug' => $this->agent->slug ?? null,
            'question' => $this->question,
            'answer' => $this->answer,
            'validation_count' => $this->validation_count,
            'rejection_count' => $this->rejection_count,
            'requires_handoff' => $this->requires_handoff,
            'confidence_score' => $this->confidence_score,
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
