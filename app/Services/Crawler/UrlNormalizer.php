<?php

declare(strict_types=1);

namespace App\Services\Crawler;

class UrlNormalizer
{
    /**
     * Normalise une URL pour déduplication
     */
    public function normalize(string $url): string
    {
        $parsed = parse_url($url);

        if (!$parsed || empty($parsed['host'])) {
            return $url;
        }

        // Lowercase scheme et host
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host']);

        // Port (omettre si standard)
        $port = '';
        if (!empty($parsed['port'])) {
            if (($scheme === 'http' && $parsed['port'] !== 80) ||
                ($scheme === 'https' && $parsed['port'] !== 443)) {
                $port = ':' . $parsed['port'];
            }
        }

        // Path normalisé
        $path = $this->normalizePath($parsed['path'] ?? '/');

        // Query params triés
        $query = $this->normalizeQuery($parsed['query'] ?? '');

        // Ignorer le fragment
        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    /**
     * Génère le hash SHA256 d'une URL normalisée
     */
    public function hash(string $url): string
    {
        return hash('sha256', $this->normalize($url));
    }

    /**
     * Résout une URL relative par rapport à une URL de base
     */
    public function resolve(string $relativeUrl, string $baseUrl): string
    {
        // URL absolue
        if (preg_match('#^https?://#i', $relativeUrl)) {
            return $relativeUrl;
        }

        // URL protocol-relative
        if (str_starts_with($relativeUrl, '//')) {
            $baseParsed = parse_url($baseUrl);
            return ($baseParsed['scheme'] ?? 'https') . ':' . $relativeUrl;
        }

        $baseParsed = parse_url($baseUrl);
        $scheme = $baseParsed['scheme'] ?? 'https';
        $host = $baseParsed['host'] ?? '';
        $port = isset($baseParsed['port']) ? ':' . $baseParsed['port'] : '';
        $basePath = $baseParsed['path'] ?? '/';

        // URL absolue depuis la racine
        if (str_starts_with($relativeUrl, '/')) {
            return "{$scheme}://{$host}{$port}{$relativeUrl}";
        }

        // URL relative
        $baseDir = dirname($basePath);
        if ($baseDir === '\\' || $baseDir === '.') {
            $baseDir = '/';
        }

        $resolvedPath = $this->resolvePath($baseDir . '/' . $relativeUrl);

        return "{$scheme}://{$host}{$port}{$resolvedPath}";
    }

    /**
     * Extrait le domaine d'une URL
     */
    public function getDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        return isset($parsed['host']) ? strtolower($parsed['host']) : null;
    }

    /**
     * Vérifie si une URL appartient à un domaine autorisé
     */
    public function isAllowedDomain(string $url, array $allowedDomains): bool
    {
        if (empty($allowedDomains)) {
            return true;
        }

        $domain = $this->getDomain($url);
        if (!$domain) {
            return false;
        }

        foreach ($allowedDomains as $allowed) {
            $allowed = strtolower(trim($allowed));

            // Match exact ou sous-domaine
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si une URL est valide et crawlable
     */
    public function isValidUrl(string $url): bool
    {
        // Doit commencer par http ou https
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        // Pas de protocoles dangereux
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return false;
        }

        // Pas d'IP locales
        $host = strtolower($parsed['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'])) {
            return false;
        }

        // Pas de plages IP privées
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extrait le chemin relatif d'une URL
     */
    public function getPath(string $url): string
    {
        $parsed = parse_url($url);
        return $parsed['path'] ?? '/';
    }

    /**
     * Normalise un chemin (résout .., ., double slashes)
     */
    private function normalizePath(string $path): string
    {
        if (empty($path)) {
            return '/';
        }

        // Décoder les caractères encodés
        $path = urldecode($path);

        // Résoudre les segments . et ..
        $path = $this->resolvePath($path);

        // Ré-encoder proprement
        $segments = explode('/', $path);
        $encoded = array_map('rawurlencode', $segments);

        $result = implode('/', $encoded);

        // Trailing slash : garder seulement pour la racine
        if ($result !== '/' && str_ends_with($result, '/')) {
            $result = rtrim($result, '/');
        }

        return $result ?: '/';
    }

    /**
     * Résout les segments . et .. dans un chemin
     */
    private function resolvePath(string $path): string
    {
        $segments = explode('/', $path);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($resolved);
            } elseif ($segment !== '' && $segment !== '.') {
                $resolved[] = $segment;
            }
        }

        return '/' . implode('/', $resolved);
    }

    /**
     * Normalise les query params (tri alphabétique)
     */
    private function normalizeQuery(string $query): string
    {
        if (empty($query)) {
            return '';
        }

        parse_str($query, $params);

        // Supprimer les params vides courants (tracking)
        $trackingParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'];
        foreach ($trackingParams as $param) {
            unset($params[$param]);
        }

        if (empty($params)) {
            return '';
        }

        // Trier alphabétiquement
        ksort($params);

        return '?' . http_build_query($params);
    }
}
