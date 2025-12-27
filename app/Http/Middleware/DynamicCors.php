<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AgentDeployment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour gérer les CORS dynamiquement selon le déploiement.
 *
 * Doit être utilisé APRÈS ValidateDeploymentKey pour les routes whitelabel.
 * Pour les requêtes OPTIONS (preflight), il peut être utilisé en standalone.
 */
class DynamicCors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Pour les requêtes OPTIONS (preflight), répondre avec les headers CORS
        if ($request->isMethod('OPTIONS')) {
            return $this->handlePreflight($request);
        }

        $response = $next($request);

        return $this->addCorsHeaders($request, $response);
    }

    /**
     * Gère les requêtes preflight OPTIONS.
     */
    private function handlePreflight(Request $request): Response
    {
        $origin = $request->header('Origin', '*');

        return response('', 204)
            ->withHeaders($this->getCorsHeaders($origin));
    }

    /**
     * Ajoute les headers CORS à la réponse.
     */
    private function addCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->header('Origin');

        if (!$origin) {
            return $response;
        }

        // Vérifier si on a un déploiement validé
        /** @var AgentDeployment|null $deployment */
        $deployment = $request->attributes->get('deployment');

        if ($deployment) {
            // Vérifier si l'origine est dans les domaines autorisés
            $originHost = parse_url($origin, PHP_URL_HOST);

            if ($originHost && $deployment->isDomainAllowed($originHost)) {
                foreach ($this->getCorsHeaders($origin) as $header => $value) {
                    $response->headers->set($header, $value);
                }
            }
        } else {
            // En développement, autoriser tout
            if (app()->environment('local', 'development', 'testing')) {
                foreach ($this->getCorsHeaders($origin) as $header => $value) {
                    $response->headers->set($header, $value);
                }
            }
        }

        return $response;
    }

    /**
     * Retourne les headers CORS pour une origine donnée.
     */
    private function getCorsHeaders(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Deployment-Key, X-Requested-With, Accept',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400', // 24 heures
        ];
    }
}
