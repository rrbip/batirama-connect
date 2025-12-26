<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebCrawl extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'agent_id',
        'start_url',
        'allowed_domains',
        'url_filter_mode',
        'url_patterns',
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
        'pages_indexed',
        'pages_skipped',
        'pages_error',
        'documents_found',
        'images_found',
        'total_size_bytes',
        'started_at',
        'paused_at',
        'completed_at',
    ];

    protected $casts = [
        'allowed_domains' => 'array',
        'url_patterns' => 'array',
        'custom_headers' => 'array',
        'respect_robots_txt' => 'boolean',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * L'agent IA associé
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
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
                'matched_pattern',
                'skip_reason',
                'document_id',
                'error_message',
                'retry_count',
                'fetched_at',
                'indexed_at',
            ])
            ->withTimestamps();
    }

    /**
     * Les entrées pivot de ce crawl
     */
    public function urlEntries(): HasMany
    {
        return $this->hasMany(WebCrawlUrlCrawl::class, 'crawl_id');
    }

    /**
     * Les documents créés par ce crawl
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
     * Chiffre et stocke les credentials
     */
    public function setAuthCredentialsAttribute(?array $value): void
    {
        $this->attributes['auth_credentials'] = $value
            ? encrypt(json_encode($value))
            : null;
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
     * Calcule le pourcentage de progression
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
}
