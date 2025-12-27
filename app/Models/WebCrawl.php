<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Représente un crawl de site web.
 *
 * Le crawl est maintenant un cache pur, sans lien direct avec un agent.
 * Les agents sont liés via AgentWebCrawl avec leur propre configuration.
 */
class WebCrawl extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'start_url',
        'allowed_domains',
        'max_depth',
        'max_pages',
        'max_disk_mb',
        'delay_ms',
        'respect_robots_txt',
        'user_agent',
        'auth_type',
        'auth_credentials',
        'custom_headers',
        'status',
        'pages_discovered',
        'pages_crawled',
        'total_size_bytes',
        'started_at',
        'paused_at',
        'completed_at',
    ];

    protected $casts = [
        'allowed_domains' => 'array',
        'custom_headers' => 'array',
        'respect_robots_txt' => 'boolean',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Les configurations d'indexation par agent
     */
    public function agentConfigs(): HasMany
    {
        return $this->hasMany(AgentWebCrawl::class);
    }

    /**
     * Les agents liés à ce crawl (via AgentWebCrawl)
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_web_crawls')
            ->withPivot([
                'url_filter_mode',
                'url_patterns',
                'content_types',
                'chunk_strategy',
                'index_status',
                'pages_indexed',
                'pages_skipped',
                'pages_error',
                'last_indexed_at',
            ])
            ->withTimestamps();
    }

    /**
     * Les URLs découvertes par ce crawl (via pivot)
     */
    public function urls(): BelongsToMany
    {
        return $this->belongsToMany(WebCrawlUrl::class, 'web_crawl_url_crawl', 'crawl_id', 'crawl_url_id')
            ->withPivot([
                'parent_id',
                'depth',
                'status',
                'error_message',
                'retry_count',
                'fetched_at',
            ])
            ->withTimestamps();
    }

    /**
     * Les entrées pivot de ce crawl (pour le cache)
     */
    public function urlEntries(): HasMany
    {
        return $this->hasMany(WebCrawlUrlCrawl::class, 'crawl_id');
    }

    /**
     * Tous les documents créés par ce crawl (tous agents confondus)
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'web_crawl_id');
    }

    /**
     * Déchiffre les credentials d'authentification
     */
    public function getDecryptedCredentialsAttribute(): ?array
    {
        if (empty($this->auth_credentials)) {
            return null;
        }

        try {
            return json_decode(decrypt($this->auth_credentials), true);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Vérifie si le crawl est en cours
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Vérifie si le crawl est terminé
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }

    /**
     * Vérifie si le crawl peut être repris
     */
    public function canResume(): bool
    {
        return $this->status === 'paused';
    }

    /**
     * Calcule le pourcentage de progression du crawl (cache)
     */
    public function getProgressPercentAttribute(): int
    {
        if ($this->pages_discovered === 0) {
            return 0;
        }

        return (int) round(($this->pages_crawled / $this->pages_discovered) * 100);
    }

    /**
     * Formate la taille totale pour l'affichage
     */
    public function getTotalSizeForHumansAttribute(): string
    {
        $bytes = $this->total_size_bytes;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Retourne le domaine principal du site
     */
    public function getDomainAttribute(): string
    {
        return parse_url($this->start_url, PHP_URL_HOST) ?? '';
    }

    /**
     * Compte le nombre total de pages indexées (tous agents)
     */
    public function getTotalPagesIndexedAttribute(): int
    {
        return $this->agentConfigs()->sum('pages_indexed');
    }

    /**
     * Vérifie si au moins un agent est en cours d'indexation
     */
    public function hasIndexingAgents(): bool
    {
        return $this->agentConfigs()->where('index_status', 'indexing')->exists();
    }
}
