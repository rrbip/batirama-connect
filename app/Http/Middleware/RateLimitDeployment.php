<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AgentDeployment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour le rate limiting par déploiement et par IP.
 *
 * Doit être utilisé APRÈS ValidateDeploymentKey.
 */
class RateLimitDeployment
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

        $ip = $request->ip();

        // Rate limit par IP pour ce déploiement
        if (!$this->checkIpRateLimit($deployment, $ip)) {
            return $this->rateLimited('Limite de requêtes par IP atteinte');
        }

        // Rate limit global pour ce déploiement (optionnel)
        if (!$this->checkDeploymentRateLimit($deployment)) {
            return $this->rateLimited('Limite de requêtes du déploiement atteinte');
        }

        return $next($request);
    }

    /**
     * Vérifie le rate limit par IP.
     */
    private function checkIpRateLimit(AgentDeployment $deployment, string $ip): bool
    {
        $limit = $deployment->rate_limit_per_ip ?? 60; // 60 req/min par défaut
        $key = "rate_limit:deployment:{$deployment->id}:ip:{$ip}";

        $current = Cache::get($key, 0);

        if ($current >= $limit) {
            return false;
        }

        Cache::put($key, $current + 1, 60); // Expire après 1 minute

        return true;
    }

    /**
     * Vérifie le rate limit global du déploiement.
     */
    private function checkDeploymentRateLimit(AgentDeployment $deployment): bool
    {
        // Limite configurable par l'agent
        $agentLimit = $deployment->agent->whitelabel_config['max_requests_per_minute'] ?? null;

        if ($agentLimit === null) {
            return true; // Pas de limite globale
        }

        $key = "rate_limit:deployment:{$deployment->id}:global";
        $current = Cache::get($key, 0);

        if ($current >= $agentLimit) {
            return false;
        }

        Cache::put($key, $current + 1, 60);

        return true;
    }

    /**
     * Réponse 429 Rate Limited.
     */
    private function rateLimited(string $message): Response
    {
        return response()->json([
            'error' => 'rate_limited',
            'message' => $message,
            'retry_after' => 60,
        ], 429)->withHeaders([
            'Retry-After' => 60,
            'X-RateLimit-Reset' => now()->addMinute()->timestamp,
        ]);
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
