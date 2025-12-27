<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RobotsTxtParser
{
    private array $rules = [];
    private ?int $crawlDelay = null;
    private array $sitemaps = [];
    private string $userAgent;

    public function __construct(string $userAgent = 'IA-Manager')
    {
        $this->userAgent = $userAgent;
    }

    /**
     * Charge et parse le robots.txt d'un site
     */
    public function load(string $baseUrl): bool
    {
        $robotsUrl = rtrim($baseUrl, '/') . '/robots.txt';

        try {
            $response = Http::timeout(10)
                ->withUserAgent($this->userAgent)
                ->get($robotsUrl);

            if (!$response->successful()) {
                // Pas de robots.txt = tout autorisé
                Log::info('No robots.txt found', ['url' => $robotsUrl]);
                return true;
            }

            $this->parse($response->body());
            return true;

        } catch (\Exception $e) {
            Log::warning('Failed to fetch robots.txt', [
                'url' => $robotsUrl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Parse le contenu du robots.txt
     */
    public function parse(string $content): void
    {
        $this->rules = [];
        $this->crawlDelay = null;
        $this->sitemaps = [];

        $lines = explode("\n", $content);
        $currentUserAgent = null;
        $isRelevant = false;

        foreach ($lines as $line) {
            // Supprimer les commentaires
            $line = trim(explode('#', $line)[0]);

            if (empty($line)) {
                continue;
            }

            // Parser la directive
            if (!str_contains($line, ':')) {
                continue;
            }

            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = strtolower($directive);

            switch ($directive) {
                case 'user-agent':
                    $currentUserAgent = strtolower($value);
                    $isRelevant = $this->matchesUserAgent($currentUserAgent);
                    break;

                case 'disallow':
                    if ($isRelevant && $value !== '') {
                        $this->rules[] = ['type' => 'disallow', 'pattern' => $value];
                    }
                    break;

                case 'allow':
                    if ($isRelevant && $value !== '') {
                        $this->rules[] = ['type' => 'allow', 'pattern' => $value];
                    }
                    break;

                case 'crawl-delay':
                    if ($isRelevant && is_numeric($value)) {
                        $this->crawlDelay = max($this->crawlDelay ?? 0, (int) $value);
                    }
                    break;

                case 'sitemap':
                    if (!empty($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                        $this->sitemaps[] = $value;
                    }
                    break;
            }
        }
    }

    /**
     * Vérifie si une URL est autorisée
     */
    public function isAllowed(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        // Trier les règles par longueur de pattern (plus long = plus spécifique)
        $sortedRules = $this->rules;
        usort($sortedRules, fn($a, $b) => strlen($b['pattern']) - strlen($a['pattern']));

        foreach ($sortedRules as $rule) {
            if ($this->matchesPattern($path, $rule['pattern'])) {
                return $rule['type'] === 'allow';
            }
        }

        // Par défaut, tout est autorisé
        return true;
    }

    /**
     * Retourne le crawl-delay en secondes
     */
    public function getCrawlDelay(): ?int
    {
        return $this->crawlDelay;
    }

    /**
     * Retourne les sitemaps déclarés
     */
    public function getSitemaps(): array
    {
        return $this->sitemaps;
    }

    /**
     * Vérifie si le user-agent correspond
     */
    private function matchesUserAgent(string $robotsAgent): bool
    {
        // * correspond à tous
        if ($robotsAgent === '*') {
            return true;
        }

        // Match partiel
        $ourAgent = strtolower($this->userAgent);
        return str_contains($ourAgent, $robotsAgent) || str_contains($robotsAgent, $ourAgent);
    }

    /**
     * Vérifie si un chemin correspond à un pattern robots.txt
     */
    private function matchesPattern(string $path, string $pattern): bool
    {
        // Patterns robots.txt :
        // * = n'importe quoi
        // $ = fin de l'URL

        // Échapper les caractères spéciaux regex sauf * et $
        $regex = preg_quote($pattern, '#');

        // Remplacer * par .*
        $regex = str_replace('\*', '.*', $regex);

        // $ à la fin signifie "fin exacte"
        if (!str_ends_with($regex, '\$')) {
            $regex .= '.*';
        } else {
            $regex = substr($regex, 0, -2) . '$';
        }

        $regex = '#^' . $regex . '#i';

        return (bool) preg_match($regex, $path);
    }
}
