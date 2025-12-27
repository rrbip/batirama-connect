<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AgentDeployment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour authentifier les requêtes via deployment_key.
 *
 * Utilisé pour les endpoints whitelabel (/api/whitelabel/*).
 * Le deployment_key est passé dans le header X-Deployment-Key ou en query param.
 */
class ValidateDeploymentKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $deploymentKey = $this->extractDeploymentKey($request);

        if (!$deploymentKey) {
            return $this->unauthorized('Clé de déploiement manquante');
        }

        // Rechercher le déploiement (avec cache)
        $deployment = $this->findDeployment($deploymentKey);

        if (!$deployment) {
            Log::warning('Invalid deployment key attempt', [
                'ip' => $request->ip(),
                'key_prefix' => substr($deploymentKey, 0, 10) . '...',
            ]);

            return $this->unauthorized('Clé de déploiement invalide');
        }

        // Vérifier si le déploiement est actif
        if (!$deployment->is_active) {
            return $this->forbidden('Déploiement désactivé');
        }

        // Vérifier si l'agent est actif
        if (!$deployment->agent || !$deployment->agent->is_active) {
            return $this->forbidden('Agent désactivé');
        }

        // Vérifier si l'éditeur est actif
        if (!$deployment->editor) {
            return $this->forbidden('Éditeur introuvable');
        }

        // Attacher le déploiement à la requête
        $request->attributes->set('deployment', $deployment);
        $request->attributes->set('agent', $deployment->agent);
        $request->attributes->set('editor', $deployment->editor);

        return $next($request);
    }

    /**
     * Extrait la clé de déploiement de la requête.
     */
    private function extractDeploymentKey(Request $request): ?string
    {
        // Priorité : header, puis query param
        return $request->header('X-Deployment-Key')
            ?? $request->query('deployment_key');
    }

    /**
     * Recherche le déploiement avec cache.
     */
    private function findDeployment(string $key): ?AgentDeployment
    {
        $cacheKey = 'deployment:' . hash('sha256', $key);

        return Cache::remember($cacheKey, 300, function () use ($key) {
            return AgentDeployment::where('deployment_key', $key)
                ->with(['agent', 'editor', 'allowedDomains'])
                ->first();
        });
    }

    /**
     * Réponse 401 Unauthorized.
     */
    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => 'invalid_deployment_key',
            'message' => $message,
        ], 401);
    }

    /**
     * Réponse 403 Forbidden.
     */
    private function forbidden(string $message): Response
    {
        return response()->json([
            'error' => 'deployment_disabled',
            'message' => $message,
        ], 403);
    }
}
