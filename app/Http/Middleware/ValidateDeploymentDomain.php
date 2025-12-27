<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AgentDeployment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour valider le domaine d'origine des requêtes whitelabel.
 *
 * Vérifie que la requête provient d'un domaine autorisé pour le déploiement.
 * Doit être utilisé APRÈS ValidateDeploymentKey.
 */
class ValidateDeploymentDomain
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AgentDeployment|null $deployment */
        $deployment = $request->attributes->get('deployment');

        if (!$deployment) {
            return $this->serverError('Déploiement non résolu');
        }

        // Extraire le domaine d'origine
        $origin = $this->extractOrigin($request);

        if (!$origin) {
            // En développement, on peut être plus permissif
            if (app()->environment('local', 'development', 'testing')) {
                return $next($request);
            }

            Log::warning('Missing origin for deployment request', [
                'deployment_id' => $deployment->id,
                'ip' => $request->ip(),
            ]);

            return $this->forbidden('Origine de la requête non identifiable');
        }

        // Vérifier si le domaine est autorisé
        if (!$deployment->isDomainAllowed($origin)) {
            Log::warning('Unauthorized domain for deployment', [
                'deployment_id' => $deployment->id,
                'deployment_name' => $deployment->name,
                'origin' => $origin,
                'ip' => $request->ip(),
            ]);

            return $this->forbidden("Domaine non autorisé: {$origin}");
        }

        // Ajouter l'origine validée à la requête
        $request->attributes->set('validated_origin', $origin);

        return $next($request);
    }

    /**
     * Extrait le domaine d'origine de la requête.
     */
    private function extractOrigin(Request $request): ?string
    {
        // Priorité : Origin header, puis Referer
        $origin = $request->header('Origin');

        if ($origin) {
            return $this->parseHost($origin);
        }

        $referer = $request->header('Referer');

        if ($referer) {
            return $this->parseHost($referer);
        }

        return null;
    }

    /**
     * Parse une URL pour extraire le host.
     */
    private function parseHost(string $url): ?string
    {
        $parsed = parse_url($url);

        return $parsed['host'] ?? null;
    }

    /**
     * Réponse 403 Forbidden.
     */
    private function forbidden(string $message): Response
    {
        return response()->json([
            'error' => 'domain_not_allowed',
            'message' => $message,
        ], 403);
    }

    /**
     * Réponse 500 Server Error.
     */
    private function serverError(string $message): Response
    {
        return response()->json([
            'error' => 'server_error',
            'message' => $message,
        ], 500);
    }
}
