<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Marketplace\LanguageDetector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class WebCrawlUrl extends Model
{
    use HasFactory;

    protected $fillable = [
        'url',
        'url_hash',
        'canonical_url',
        'canonical_hash',
        'duplicate_of_id',
        'storage_path',
        'content_hash',
        'http_status',
        'content_type',
        'content_length',
        'locale',
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
                'error_message',
                'retry_count',
                'fetched_at',
            ])
            ->withTimestamps();
    }

    /**
     * Les entrées d'indexation par agent pour cette URL
     */
    public function agentIndexEntries(): HasMany
    {
        return $this->hasMany(AgentWebCrawlUrl::class, 'web_crawl_url_id');
    }

    /**
     * Les documents créés à partir de cette URL (tous agents)
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'crawl_url_id');
    }

    /**
     * L'URL originale dont celle-ci est un doublon
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(WebCrawlUrl::class, 'duplicate_of_id');
    }

    /**
     * Les URLs qui sont des doublons de celle-ci
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(WebCrawlUrl::class, 'duplicate_of_id');
    }

    /**
     * Vérifie si cette URL est un doublon
     */
    public function isDuplicate(): bool
    {
        return $this->duplicate_of_id !== null;
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

    /**
     * Get the stored HTML content.
     */
    public function getContent(): ?string
    {
        if (empty($this->storage_path)) {
            return null;
        }

        // Use Storage facade (same as admin preview) for consistency
        if (!Storage::disk('local')->exists($this->storage_path)) {
            return null;
        }

        return Storage::disk('local')->get($this->storage_path);
    }

    /**
     * Extract text content from HTML (removes tags, scripts, styles).
     */
    public function getTextContent(): ?string
    {
        $html = $this->getContent();

        if (empty($html)) {
            return null;
        }

        // Remove script and style tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // Remove HTML tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Detect and set the locale from content.
     * For HTML: uses lang attribute, URL patterns, then content analysis.
     * For other documents (PDF, etc.): uses URL patterns and content analysis.
     */
    public function detectLocale(): ?string
    {
        $detector = app(LanguageDetector::class);
        $locale = null;

        // For HTML pages, try lang attribute first (most reliable)
        if ($this->isHtml()) {
            $html = $this->getContent();
            if (!empty($html)) {
                $locale = $detector->detectFromHtmlLangAttribute($html);
            }
        }

        // Try URL patterns (works for all content types)
        if (!$locale) {
            $locale = $detector->detectFromUrl($this->url);
        }

        // For non-HTML or if still no locale, try content analysis
        if (!$locale) {
            $content = $this->getTextContent();
            if (!empty($content)) {
                $locale = $detector->detectFromContent(mb_substr($content, 0, 5000));
            }
        }

        return $locale;
    }

    /**
     * Detect locale and save to database.
     */
    public function detectAndSaveLocale(): ?string
    {
        $locale = $this->detectLocale();

        if ($locale && $locale !== $this->locale) {
            $this->update(['locale' => $locale]);
        }

        return $locale;
    }

    /**
     * Get locale name for display.
     */
    public function getLocaleNameAttribute(): ?string
    {
        if (!$this->locale) {
            return null;
        }

        $detector = app(LanguageDetector::class);
        return $detector->getLocaleName($this->locale);
    }
}
