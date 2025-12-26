<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebCrawlUrl extends Model
{
    use HasFactory;

    protected $fillable = [
        'url',
        'url_hash',
        'storage_path',
        'content_hash',
        'http_status',
        'content_type',
        'content_length',
        'last_modified',
        'etag',
    ];

    protected $casts = [
        'content_length' => 'integer',
        'http_status' => 'integer',
    ];

    /**
     * Les crawls qui ont découvert cette URL
     */
    public function crawls(): BelongsToMany
    {
        return $this->belongsToMany(WebCrawl::class, 'web_crawl_url_crawl', 'crawl_url_id', 'crawl_id')
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
     * Les documents créés à partir de cette URL
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'crawl_url_id');
    }

    /**
     * Génère le hash de l'URL normalisée
     */
    public static function generateUrlHash(string $url): string
    {
        // Normaliser l'URL avant de hasher
        $normalized = self::normalizeUrl($url);
        return hash('sha256', $normalized);
    }

    /**
     * Normalise une URL pour déduplication
     */
    public static function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (!$parsed) {
            return $url;
        }

        // Lowercase scheme et host
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host'] ?? '');

        // Path sans trailing slash (sauf racine)
        $path = $parsed['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // Trier les query params
        $query = '';
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            ksort($params);
            $query = '?' . http_build_query($params);
        }

        // Ignorer le fragment (#anchor)
        return "{$scheme}://{$host}{$path}{$query}";
    }

    /**
     * Vérifie si le contenu est de type HTML
     */
    public function isHtml(): bool
    {
        return str_contains($this->content_type ?? '', 'text/html');
    }

    /**
     * Vérifie si le contenu est un PDF
     */
    public function isPdf(): bool
    {
        return str_contains($this->content_type ?? '', 'application/pdf');
    }

    /**
     * Vérifie si le contenu est une image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->content_type ?? '', 'image/');
    }

    /**
     * Vérifie si c'est une réponse de succès HTTP
     */
    public function isSuccess(): bool
    {
        return $this->http_status >= 200 && $this->http_status < 300;
    }

    /**
     * Vérifie si c'est une redirection
     */
    public function isRedirect(): bool
    {
        return $this->http_status >= 300 && $this->http_status < 400;
    }

    /**
     * Vérifie si c'est une erreur client
     */
    public function isClientError(): bool
    {
        return $this->http_status >= 400 && $this->http_status < 500;
    }

    /**
     * Vérifie si c'est une erreur serveur
     */
    public function isServerError(): bool
    {
        return $this->http_status >= 500;
    }
}
