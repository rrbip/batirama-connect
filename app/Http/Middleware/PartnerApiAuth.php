<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Partner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PartnerApiAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return $this->unauthorized('Clé API manquante');
        }

        // Rechercher le token (avec cache)
        $apiToken = $this->findApiToken($token);

        if (!$apiToken) {
            Log::warning('Invalid API key attempt', [
                'ip' => $request->ip(),
                'token_prefix' => substr($token, 0, 10) . '...',
            ]);

            return $this->unauthorized('Clé API invalide ou expirée');
        }

        // Vérifier l'expiration
        if ($apiToken->expires_at && $apiToken->expires_at->isPast()) {
            return $this->unauthorized('Clé API expirée');
        }

        // Vérifier le partenaire
        $partner = $apiToken->partner;

        if (!$partner || !$partner->is_active) {
            return $this->forbidden('Compte partenaire suspendu');
        }

        // Vérifier le rate limiting
        if (!$this->checkRateLimit($partner)) {
            return $this->rateLimited();
        }

        // Mettre à jour les statistiques
        $apiToken->increment('requests_count');
        $apiToken->update(['last_used_at' => now()]);

        // Attacher le partenaire à la requête
        $request->attributes->set('partner', $partner);
        $request->attributes->set('api_token', $apiToken);

        return $next($request);
    }

    /**
     * Extrait le token du header Authorization
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }

    /**
     * Recherche le token API avec cache
     */
    private function findApiToken(string $token): ?ApiToken
    {
        $cacheKey = 'api_token:' . hash('sha256', $token);

        return Cache::remember($cacheKey, 300, function () use ($token) {
            return ApiToken::where('token', hash('sha256', $token))
                ->where('is_active', true)
                ->with('partner')
                ->first();
        });
    }

    /**
     * Vérifie le rate limiting
     */
    private function checkRateLimit(Partner $partner): bool
    {
        $key = "rate_limit:partner:{$partner->id}";
        $limit = $partner->rate_limit ?? 100; // 100 requêtes/minute par défaut

        $current = Cache::get($key, 0);

        if ($current >= $limit) {
            return false;
        }

        Cache::put($key, $current + 1, 60);

        return true;
    }

    /**
     * Réponse 401 Unauthorized
     */
    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => 'invalid_api_key',
            'message' => $message,
        ], 401);
    }

    /**
     * Réponse 403 Forbidden
     */
    private function forbidden(string $message): Response
    {
        return response()->json([
            'error' => 'partner_suspended',
            'message' => $message,
        ], 403);
    }

    /**
     * Réponse 429 Rate Limited
     */
    private function rateLimited(): Response
    {
        return response()->json([
            'error' => 'rate_limited',
            'message' => 'Limite de requêtes atteinte',
            'retry_after' => 60,
        ], 429);
    }
}
