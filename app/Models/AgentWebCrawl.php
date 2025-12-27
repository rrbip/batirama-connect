<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuration d'indexation d'un crawl pour un agent spécifique.
 *
 * Chaque agent peut avoir sa propre configuration de filtrage,
 * types de contenu et stratégie de chunking pour un même crawl.
 */
class AgentWebCrawl extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'web_crawl_id',
        'url_filter_mode',
        'url_patterns',
        'content_types',
        'chunk_strategy',
        'index_status',
        'pages_indexed',
        'pages_skipped',
        'pages_error',
        'last_indexed_at',
    ];

    protected $casts = [
        'url_patterns' => 'array',
        'content_types' => 'array',
        'last_indexed_at' => 'datetime',
    ];

    /**
     * L'agent IA associé
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Le crawl source
     */
    public function webCrawl(): BelongsTo
    {
        return $this->belongsTo(WebCrawl::class);
    }

    /**
     * Les URLs indexées pour cet agent
     */
    public function urlEntries(): HasMany
    {
        return $this->hasMany(AgentWebCrawlUrl::class);
    }

    /**
     * Les documents créés pour cet agent via ce crawl
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'agent_web_crawl_id');
    }

    /**
     * Retourne la stratégie de chunking effective
     * (override local ou défaut de l'agent)
     */
    public function getEffectiveChunkStrategyAttribute(): string
    {
        return $this->chunk_strategy ?? $this->agent->default_chunk_strategy ?? 'simple';
    }

    /**
     * Vérifie si un content-type doit être indexé
     */
    public function shouldIndexContentType(string $contentType): bool
    {
        $types = $this->content_types ?? ['html', 'pdf', 'image', 'document'];

        // Normaliser le content-type
        $contentType = strtolower($contentType);

        if (in_array('html', $types) && str_contains($contentType, 'text/html')) {
            return true;
        }

        if (in_array('pdf', $types) && str_contains($contentType, 'application/pdf')) {
            return true;
        }

        if (in_array('image', $types) && str_starts_with($contentType, 'image/')) {
            return true;
        }

        if (in_array('document', $types)) {
            $documentTypes = [
                'text/plain',
                'text/markdown',
                'application/msword',
                'application/vnd.openxmlformats-officedocument',
            ];
            foreach ($documentTypes as $type) {
                if (str_contains($contentType, $type)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Vérifie si une URL correspond aux patterns de filtrage
     */
    public function matchesUrlPatterns(string $url): bool
    {
        $patterns = $this->url_patterns ?? [];

        if (empty($patterns)) {
            // Pas de patterns = tout accepter en mode exclude, rien en mode include
            return $this->url_filter_mode === 'exclude';
        }

        // Pattern spécial "*" = tout matcher
        if (in_array('*', $patterns, true)) {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        foreach ($patterns as $pattern) {
            if ($this->urlMatchesPattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Détermine si une URL doit être indexée selon les filtres
     */
    public function shouldIndexUrl(string $url): bool
    {
        $matches = $this->matchesUrlPatterns($url);

        return $this->url_filter_mode === 'exclude'
            ? ! $matches  // Exclure = indexer si ça ne matche PAS
            : $matches;   // Inclure = indexer si ça matche
    }

    /**
     * Vérifie si un path correspond à un pattern
     */
    private function urlMatchesPattern(string $path, string $pattern): bool
    {
        // Regex si commence par ^
        if (str_starts_with($pattern, '^')) {
            return (bool) preg_match('/' . $pattern . '/', $path);
        }

        // Wildcard simple
        $regex = str_replace(
            ['*', '/'],
            ['.*', '\/'],
            $pattern
        );

        return (bool) preg_match('/^' . $regex . '$/', $path);
    }

    /**
     * Vérifie si l'indexation est en cours
     */
    public function isIndexing(): bool
    {
        return $this->index_status === 'indexing';
    }

    /**
     * Vérifie si l'indexation est terminée
     */
    public function isIndexed(): bool
    {
        return $this->index_status === 'indexed';
    }

    /**
     * Calcule le pourcentage d'indexation
     */
    public function getIndexProgressPercentAttribute(): int
    {
        $total = $this->pages_indexed + $this->pages_skipped + $this->pages_error;
        $urlCount = $this->webCrawl->pages_crawled ?? 0;

        if ($urlCount === 0) {
            return 0;
        }

        return (int) round(($total / $urlCount) * 100);
    }
}
