<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table pivot entre WebCrawl et WebCrawlUrl.
 *
 * Représente le statut de récupération (cache) d'une URL dans un crawl.
 * L'indexation est maintenant gérée par AgentWebCrawlUrl.
 */
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
        'error_message',
        'retry_count',
        'fetched_at',
    ];

    protected $casts = [
        'depth' => 'integer',
        'retry_count' => 'integer',
        'fetched_at' => 'datetime',
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
     * Vérifie si l'URL est en attente de traitement
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Vérifie si l'URL a été récupérée
     */
    public function isFetched(): bool
    {
        return $this->status === 'fetched';
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
            'error' => 'Erreur',
            default => $this->status,
        };
    }
}
