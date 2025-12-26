<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebCrawlUrlCrawl extends Model
{
    use HasFactory;

    protected $table = 'web_crawl_url_crawl';

    protected $fillable = [
        'crawl_id',
        'crawl_url_id',
        'parent_id',
        'depth',
        'status',
        'matched_pattern',
        'skip_reason',
        'document_id',
        'error_message',
        'retry_count',
        'fetched_at',
        'indexed_at',
    ];

    protected $casts = [
        'depth' => 'integer',
        'retry_count' => 'integer',
        'fetched_at' => 'datetime',
        'indexed_at' => 'datetime',
    ];

    /**
     * Le crawl associé
     */
    public function crawl(): BelongsTo
    {
        return $this->belongsTo(WebCrawl::class, 'crawl_id');
    }

    /**
     * L'URL associée
     */
    public function url(): BelongsTo
    {
        return $this->belongsTo(WebCrawlUrl::class, 'crawl_url_id');
    }

    /**
     * L'entrée parente (pour l'arborescence)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Le document créé (si indexé)
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Vérifie si l'URL est en attente de traitement
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Vérifie si l'URL a été indexée
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
    public function isError(): bool
    {
        return $this->status === 'error';
    }

    /**
     * Peut être réessayée
     */
    public function canRetry(): bool
    {
        return $this->status === 'error' && $this->retry_count < 3;
    }

    /**
     * Retourne une description lisible du statut
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'En attente',
            'fetching' => 'En cours',
            'fetched' => 'Récupéré',
            'indexed' => 'Indexé',
            'skipped' => 'Ignoré',
            'error' => 'Erreur',
            default => $this->status,
        };
    }

    /**
     * Retourne une description lisible de la raison du skip
     */
    public function getSkipReasonLabelAttribute(): ?string
    {
        if (!$this->skip_reason) {
            return null;
        }

        return match ($this->skip_reason) {
            'pattern_exclude' => 'Exclu par pattern',
            'pattern_not_include' => 'Non inclus par pattern',
            'robots_txt' => 'Interdit par robots.txt',
            'unsupported_type' => 'Type non supporté',
            'content_too_large' => 'Contenu trop volumineux',
            'auth_required' => 'Authentification requise',
            'timeout' => 'Timeout',
            'http_error' => 'Erreur HTTP',
            'max_depth' => 'Profondeur max atteinte',
            'max_pages' => 'Limite de pages atteinte',
            'duplicate' => 'URL déjà traitée',
            default => $this->skip_reason,
        };
    }
}
