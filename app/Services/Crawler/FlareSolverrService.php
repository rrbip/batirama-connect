<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service pour interagir avec FlareSolverr.
 *
 * FlareSolverr est un proxy qui résout les challenges Cloudflare
 * en utilisant un navigateur headless (Selenium).
 *
 * @see https://github.com/FlareSolverr/FlareSolverr
 */
class FlareSolverrService
{
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.flaresolverr.url', 'http://localhost:8191/v1');
        $this->timeout = config('services.flaresolverr.timeout', 60000); // 60 seconds
    }

    /**
     * Vérifie si FlareSolverr est disponible.
     */
    public function isAvailable(): bool
    {
        if (empty($this->baseUrl)) {
            return false;
        }

        try {
            $response = Http::timeout(5)->get($this->baseUrl);
            return $response->successful();
        } catch (\Exception $e) {
            Log::debug('FlareSolverr not available', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Récupère une page via FlareSolverr.
     */
    public function fetch(string $url, ?string $userAgent = null, ?string $cookies = null): array
    {
        $payload = [
            'cmd' => 'request.get',
            'url' => $url,
            'maxTimeout' => $this->timeout,
        ];

        if ($userAgent) {
            $payload['userAgent'] = $userAgent;
        }

        if ($cookies) {
            $payload['cookies'] = $this->parseCookieString($cookies);
        }

        try {
            Log::info('FlareSolverr request', ['url' => $url]);

            $response = Http::timeout(90)
                ->post($this->baseUrl, $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'status' => $response->status(),
                    'error' => 'FlareSolverr request failed: ' . $response->status(),
                ];
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'ok') {
                return [
                    'success' => false,
                    'status' => 0,
                    'error' => $data['message'] ?? 'Unknown FlareSolverr error',
                ];
            }

            $solution = $data['solution'] ?? [];

            Log::info('FlareSolverr success', [
                'url' => $url,
                'status' => $solution['status'] ?? 0,
            ]);

            return [
                'success' => true,
                'status' => $solution['status'] ?? 200,
                'headers' => $this->parseHeaders($solution['headers'] ?? []),
                'body' => $solution['response'] ?? '',
                'content_type' => $this->extractContentType($solution['headers'] ?? []),
                'content_length' => strlen($solution['response'] ?? ''),
                'cookies' => $this->formatCookies($solution['cookies'] ?? []),
                'user_agent' => $solution['userAgent'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('FlareSolverr error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 0,
                'error' => 'FlareSolverr exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Parse une chaîne de cookies en tableau pour FlareSolverr.
     */
    private function parseCookieString(string $cookieString): array
    {
        $cookies = [];
        $pairs = explode(';', $cookieString);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) continue;

            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookies[] = [
                    'name' => trim($parts[0]),
                    'value' => trim($parts[1]),
                ];
            }
        }

        return $cookies;
    }

    /**
     * Formate les cookies de FlareSolverr en chaîne.
     */
    private function formatCookies(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $cookie) {
            if (isset($cookie['name']) && isset($cookie['value'])) {
                $parts[] = $cookie['name'] . '=' . $cookie['value'];
            }
        }
        return implode('; ', $parts);
    }

    /**
     * Parse les headers de FlareSolverr.
     */
    private function parseHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[strtolower($key)] = is_array($value) ? implode(', ', $value) : $value;
        }
        return $result;
    }

    /**
     * Extrait le Content-Type des headers.
     */
    private function extractContentType(array $headers): string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'content-type') {
                return is_array($value) ? $value[0] : $value;
            }
        }
        return 'text/html';
    }
}
