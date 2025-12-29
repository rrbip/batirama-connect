<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour authentifier les éditeurs via leur API key.
 *
 * Utilisé pour les endpoints de gestion (/api/editor/*).
 * L'API key est passée dans le header Authorization: Bearer <api_key>.
 */
class EditorApiAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $this->extractApiKey($request);

        if (!$apiKey) {
            return $this->unauthorized('Clé API manquante');
        }

        // Rechercher l'éditeur (avec cache)
        $editor = $this->findEditor($apiKey);

        if (!$editor) {
            Log::warning('Invalid editor API key attempt', [
                'ip' => $request->ip(),
                'key_prefix' => substr($apiKey, 0, 10) . '...',
            ]);

            return $this->unauthorized('Clé API invalide');
        }

        // Vérifier si l'éditeur a le rôle approprié
        if (!$editor->isEditeur() && !$editor->isFabricant()) {
            return $this->forbidden('Utilisateur non autorisé');
        }

        // Vérifier si le marketplace est activé
        if (!$editor->marketplace_enabled) {
            return $this->forbidden('Marketplace non activé pour cet utilisateur');
        }

        // Vérifier le rate limiting
        if (!$this->checkRateLimit($editor)) {
            return $this->rateLimited();
        }

        // Attacher l'éditeur à la requête
        $request->attributes->set('editor', $editor);
        $request->attributes->set('authenticated_via', 'api_key');

        return $next($request);
    }

    /**
     * Extrait l'API key du header Authorization.
     */
    private function extractApiKey(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback: header X-API-Key
        return $request->header('X-API-Key');
    }

    /**
     * Recherche l'éditeur avec cache.
     */
    private function findEditor(string $apiKey): ?User
    {
        $cacheKey = 'editor_api_key:' . hash('sha256', $apiKey);

        return Cache::remember($cacheKey, 300, function () use ($apiKey) {
            return User::where('api_key', $apiKey)
                ->whereNotNull('api_key')
                ->first();
        });
    }

    /**
     * Vérifie le rate limiting.
     */
    private function checkRateLimit(User $editor): bool
    {
        $key = "rate_limit:editor:{$editor->id}";
        $limit = 100; // 100 requêtes/minute par défaut

        $current = Cache::get($key, 0);

        if ($current >= $limit) {
            return false;
        }

        Cache::put($key, $current + 1, 60);

        return true;
    }

    /**
     * Réponse 401 Unauthorized.
     */
    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => 'invalid_api_key',
            'message' => $message,
        ], 401);
    }

    /**
     * Réponse 403 Forbidden.
     */
    private function forbidden(string $message): Response
    {
        return response()->json([
            'error' => 'access_denied',
            'message' => $message,
        ], 403);
    }

    /**
     * Réponse 429 Rate Limited.
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
