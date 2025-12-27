<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statut d'indexation d'une URL pour un agent spécifique.
 *
 * Chaque URL peut avoir un statut différent par agent (indexé, skipped, error).
 */
class AgentWebCrawlUrl extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_web_crawl_id',
        'web_crawl_url_id',
        'document_id',
        'status',
        'skip_reason',
        'error_message',
        'matched_pattern',
        'indexed_at',
    ];

    protected $casts = [
        'indexed_at' => 'datetime',
    ];

    /**
     * La configuration agent-crawl parente
     */
    public function agentWebCrawl(): BelongsTo
    {
        return $this->belongsTo(AgentWebCrawl::class);
    }

    /**
     * L'URL source (cache partagé)
     */
    public function url(): BelongsTo
    {
        return $this->belongsTo(WebCrawlUrl::class, 'web_crawl_url_id');
    }

    /**
     * Le document RAG créé
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Raccourci vers l'agent
     */
    public function getAgentAttribute(): ?Agent
    {
        return $this->agentWebCrawl?->agent;
    }

    /**
     * Raccourci vers le crawl
     */
    public function getWebCrawlAttribute(): ?WebCrawl
    {
        return $this->agentWebCrawl?->webCrawl;
    }

    /**
     * Vérifie si l'URL est indexée
     */
    public function isIndexed(): bool
    {
        return $this->status === 'indexed';
    }

    /**
     * Vérifie si l'URL a été skippée
     */
    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    /**
     * Vérifie si l'URL est en erreur
     */
    public function hasError(): bool
    {
        return $this->status === 'error';
    }

    /**
     * Vérifie si l'URL est en attente
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Retourne une description lisible du statut
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'indexed' => 'Indexé',
            'skipped' => 'Ignoré',
            'error' => 'Erreur',
            'pending' => 'En attente',
            default => $this->status,
        };
    }

    /**
     * Retourne une description lisible de la raison du skip
     */
    public function getSkipReasonLabelAttribute(): ?string
    {
        if (! $this->skip_reason) {
            return null;
        }

        return match ($this->skip_reason) {
            'pattern_exclude' => 'Exclu par pattern',
            'pattern_not_include' => 'Non inclus par pattern',
            'content_type' => 'Type de contenu non supporté',
            'already_indexed' => 'Déjà indexé',
            'no_content' => 'Pas de contenu',
            'content_too_large' => 'Contenu trop volumineux',
            default => $this->skip_reason,
        };
    }
}
