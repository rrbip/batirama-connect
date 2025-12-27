<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Models\WebCrawl;
use App\Models\WebCrawlUrl;
use App\Models\WebCrawlUrlCrawl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class WebCrawlerService
{
    private UrlNormalizer $urlNormalizer;
    private RobotsTxtParser $robotsParser;

    public function __construct(UrlNormalizer $urlNormalizer)
    {
        $this->urlNormalizer = $urlNormalizer;
    }

    /**
     * Initialise le parser robots.txt pour un crawl
     */
    public function initRobotsParser(WebCrawl $crawl): RobotsTxtParser
    {
        $this->robotsParser = new RobotsTxtParser($crawl->user_agent);

        if ($crawl->respect_robots_txt) {
            $baseUrl = $this->getBaseUrl($crawl->start_url);
            $this->robotsParser->load($baseUrl);
        }

        return $this->robotsParser;
    }

    /**
     * Récupère le contenu d'une URL
     */
    public function fetch(string $url, WebCrawl $crawl): array
    {
        $options = [
            'timeout' => 30,
            'connect_timeout' => 10,
        ];

        // Headers par défaut simulant un vrai navigateur Chrome (ordre important pour anti-bot)
        $parsedUrl = parse_url($url);
        $origin = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

        $defaultHeaders = [
            'Connection' => 'keep-alive',
            'Cache-Control' => 'max-age=0',
            'Sec-Ch-Ua' => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Upgrade-Insecure-Requests' => '1',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-User' => '?1',
            'Sec-Fetch-Dest' => 'document',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => $origin . '/',
        ];

        // Préparer la requête avec HTTP/2
        $request = Http::withOptions([
                'version' => 2.0,  // HTTP/2 pour bypass Cloudflare
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                ],
            ])
            ->timeout($options['timeout'])
            ->connectTimeout($options['connect_timeout'])
            ->withUserAgent($crawl->user_agent)
            ->withHeaders($defaultHeaders);

        // Headers personnalisés (écrasent les défauts si spécifiés)
        if (!empty($crawl->custom_headers)) {
            $request = $request->withHeaders($crawl->custom_headers);
        }

        // Authentification
        if ($crawl->auth_type === 'basic') {
            $creds = $crawl->decrypted_credentials;
            if ($creds) {
                $request = $request->withBasicAuth($creds['username'] ?? '', $creds['password'] ?? '');
            }
        } elseif ($crawl->auth_type === 'cookies') {
            $creds = $crawl->decrypted_credentials;
            if ($creds && !empty($creds['cookies'])) {
                $request = $request->withHeaders(['Cookie' => $creds['cookies']]);
            }
        }

        // Vérifier l'URL existante pour cache conditionnel
        // On n'envoie les headers conditionnels que si le fichier existe en cache
        $existingUrl = WebCrawlUrl::where('url_hash', $this->urlNormalizer->hash($url))->first();
        if ($existingUrl && $existingUrl->storage_path && Storage::disk('local')->exists($existingUrl->storage_path)) {
            if ($existingUrl->etag) {
                $request = $request->withHeaders(['If-None-Match' => $existingUrl->etag]);
            }
            if ($existingUrl->last_modified) {
                $request = $request->withHeaders(['If-Modified-Since' => $existingUrl->last_modified]);
            }
        }

        try {
            $response = $request->get($url);

            return [
                'success' => true,
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
                'content_type' => $response->header('Content-Type'),
                'content_length' => (int) $response->header('Content-Length', strlen($response->body())),
                'etag' => $response->header('ETag'),
                'last_modified' => $response->header('Last-Modified'),
                'not_modified' => $response->status() === 304,
            ];

        } catch (\Exception $e) {
            Log::error('Crawler fetch error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extrait les liens d'une page HTML
     */
    public function extractLinks(string $html, string $baseUrl): array
    {
        $links = [];

        try {
            $crawler = new Crawler($html);

            // Liens <a href>
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseUrl) {
                $href = $node->attr('href');
                if ($href) {
                    $resolved = $this->resolveAndValidateUrl($href, $baseUrl);
                    if ($resolved) {
                        $links[] = $resolved;
                    }
                }
            });

            // Images <img src>
            $crawler->filter('img[src]')->each(function (Crawler $node) use (&$links, $baseUrl) {
                $src = $node->attr('src');
                if ($src) {
                    $resolved = $this->resolveAndValidateUrl($src, $baseUrl);
                    if ($resolved) {
                        $links[] = $resolved;
                    }
                }
            });

            // PDFs embarqués <embed>, <object>
            $crawler->filter('embed[src], object[data]')->each(function (Crawler $node) use (&$links, $baseUrl) {
                $src = $node->attr('src') ?? $node->attr('data');
                if ($src) {
                    $resolved = $this->resolveAndValidateUrl($src, $baseUrl);
                    if ($resolved) {
                        $links[] = $resolved;
                    }
                }
            });

            // Canonical
            $crawler->filter('link[rel="canonical"]')->each(function (Crawler $node) use (&$links, $baseUrl) {
                $href = $node->attr('href');
                if ($href) {
                    $resolved = $this->resolveAndValidateUrl($href, $baseUrl);
                    if ($resolved) {
                        $links[] = $resolved;
                    }
                }
            });

        } catch (\Exception $e) {
            Log::warning('Failed to extract links', [
                'url' => $baseUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return array_unique($links);
    }

    /**
     * Extrait le texte d'une page HTML
     */
    public function extractHtmlText(string $html): string
    {
        try {
            $crawler = new Crawler($html);

            // Supprimer les éléments non pertinents
            $crawler->filter('script, style, nav, footer, header, aside, noscript')->each(function (Crawler $node) {
                $node->getNode(0)?->parentNode?->removeChild($node->getNode(0));
            });

            // Extraire le texte du body ou de tout le document
            $body = $crawler->filter('body');
            if ($body->count() > 0) {
                $text = $body->text();
            } else {
                $text = $crawler->text();
            }

            // Nettoyer les espaces multiples
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            return $text;

        } catch (\Exception $e) {
            Log::warning('Failed to extract HTML text', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Stocke le contenu téléchargé
     */
    public function storeContent(string $content, string $contentType, string $originalUrl): string
    {
        // Déterminer l'extension
        $extension = $this->getExtensionFromContentType($contentType);

        // Générer un nom de fichier unique
        $filename = 'crawled/' . Str::uuid() . '.' . $extension;

        // Stocker le fichier
        Storage::disk('local')->put($filename, $content);

        return $filename;
    }

    /**
     * Vérifie si une URL doit être indexée selon les patterns
     */
    public function shouldIndex(string $url, WebCrawl $crawl): array
    {
        $path = $this->urlNormalizer->getPath($url);
        $patterns = $crawl->url_patterns ?? [];
        $mode = $crawl->url_filter_mode ?? 'exclude';

        if (empty($patterns)) {
            return ['should_index' => true, 'matched_pattern' => null, 'skip_reason' => null];
        }

        $matchedPattern = null;

        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($path, $pattern)) {
                $matchedPattern = $pattern;
                break;
            }
        }

        if ($mode === 'exclude') {
            // Mode exclusion : indexer si NE matche PAS
            if ($matchedPattern) {
                return [
                    'should_index' => false,
                    'matched_pattern' => $matchedPattern,
                    'skip_reason' => 'pattern_exclude',
                ];
            }
            return ['should_index' => true, 'matched_pattern' => null, 'skip_reason' => null];
        } else {
            // Mode inclusion : indexer si matche
            if ($matchedPattern) {
                return ['should_index' => true, 'matched_pattern' => $matchedPattern, 'skip_reason' => null];
            }
            return [
                'should_index' => false,
                'matched_pattern' => null,
                'skip_reason' => 'pattern_not_include',
            ];
        }
    }

    /**
     * Vérifie si une URL est autorisée par robots.txt
     */
    public function isAllowedByRobots(string $url, WebCrawl $crawl): bool
    {
        if (!$crawl->respect_robots_txt) {
            return true;
        }

        if (!isset($this->robotsParser)) {
            $this->initRobotsParser($crawl);
        }

        return $this->robotsParser->isAllowed($url);
    }

    /**
     * Retourne le délai de crawl recommandé
     */
    public function getCrawlDelay(WebCrawl $crawl): int
    {
        $configDelay = $crawl->delay_ms;

        if ($crawl->respect_robots_txt && isset($this->robotsParser)) {
            $robotsDelay = $this->robotsParser->getCrawlDelay();
            if ($robotsDelay !== null) {
                // robots.txt en secondes, on veut millisecondes
                $robotsDelayMs = $robotsDelay * 1000;
                return max($configDelay, $robotsDelayMs);
            }
        }

        return $configDelay;
    }

    /**
     * Vérifie si le type de contenu est supporté pour l'indexation
     */
    public function isSupportedContentType(string $contentType): bool
    {
        $supported = [
            'text/html',
            'application/pdf',
            'text/plain',
            'text/markdown',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/bmp',
            'image/tiff',
            'image/webp',
        ];

        foreach ($supported as $type) {
            if (str_contains($contentType, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Détermine le type de document à partir du content-type
     */
    public function getDocumentType(string $contentType): string
    {
        if (str_contains($contentType, 'text/html')) {
            return 'html';
        }
        if (str_contains($contentType, 'application/pdf')) {
            return 'pdf';
        }
        if (str_contains($contentType, 'text/plain')) {
            return 'txt';
        }
        if (str_contains($contentType, 'text/markdown')) {
            return 'md';
        }
        if (str_contains($contentType, 'msword')) {
            return 'doc';
        }
        if (str_contains($contentType, 'wordprocessingml')) {
            return 'docx';
        }
        if (str_contains($contentType, 'image/jpeg')) {
            return 'jpg';
        }
        if (str_contains($contentType, 'image/png')) {
            return 'png';
        }
        if (str_contains($contentType, 'image/gif')) {
            return 'gif';
        }
        if (str_contains($contentType, 'image/webp')) {
            return 'webp';
        }
        if (str_contains($contentType, 'image/')) {
            return 'image';
        }

        return 'unknown';
    }

    /**
     * Vérifie si c'est une image
     */
    public function isImage(string $contentType): bool
    {
        return str_starts_with($contentType, 'image/');
    }

    /**
     * Retourne l'URL de base (scheme + host)
     */
    private function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    /**
     * Résout et valide une URL
     */
    private function resolveAndValidateUrl(string $href, string $baseUrl): ?string
    {
        // Ignorer les liens vides ou javascript
        if (empty($href) || str_starts_with($href, '#') ||
            str_starts_with($href, 'javascript:') ||
            str_starts_with($href, 'mailto:') ||
            str_starts_with($href, 'tel:') ||
            str_starts_with($href, 'data:')) {
            return null;
        }

        $resolved = $this->urlNormalizer->resolve($href, $baseUrl);

        if (!$this->urlNormalizer->isValidUrl($resolved)) {
            return null;
        }

        return $this->urlNormalizer->normalize($resolved);
    }

    /**
     * Vérifie si un chemin matche un pattern
     */
    private function matchesPattern(string $path, string $pattern): bool
    {
        // Si le pattern commence par ^, c'est une regex
        if (str_starts_with($pattern, '^')) {
            return (bool) preg_match('#' . $pattern . '#i', $path);
        }

        // Sinon, pattern simple avec wildcards
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*', '.*', $regex);
        $regex = '#^' . $regex . '#i';

        return (bool) preg_match($regex, $path);
    }

    /**
     * Détermine l'extension à partir du content-type
     */
    private function getExtensionFromContentType(string $contentType): string
    {
        $map = [
            'text/html' => 'html',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/markdown' => 'md',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
        ];

        foreach ($map as $type => $ext) {
            if (str_contains($contentType, $type)) {
                return $ext;
            }
        }

        return 'bin';
    }
}
