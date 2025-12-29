<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AgentDeployment;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour vérifier les quotas de l'éditeur.
 *
 * Vérifie les quotas mensuels (sessions, messages, déploiements).
 * Doit être utilisé APRÈS ValidateDeploymentKey.
 */
class CheckEditorQuota
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $quotaType = 'session'): Response
    {
        /** @var AgentDeployment|null $deployment */
        $deployment = $request->attributes->get('deployment');

        if (!$deployment) {
            return $this->serverError('Déploiement non résolu');
        }

        /** @var User $editor */
        $editor = $deployment->editor;

        // Vérifier le quota selon le type
        $hasQuota = match ($quotaType) {
            'session' => $this->checkSessionQuota($editor, $deployment),
            'message' => $this->checkMessageQuota($editor, $deployment),
            default => true,
        };

        if (!$hasQuota) {
            return $this->quotaExceeded($quotaType);
        }

        return $next($request);
    }

    /**
     * Vérifie le quota de sessions.
     */
    private function checkSessionQuota(User $editor, AgentDeployment $deployment): bool
    {
        // Vérifier le quota de l'éditeur
        if (!$editor->hasSessionQuotaRemaining()) {
            return false;
        }

        // Vérifier le quota du déploiement
        if (!$deployment->hasSessionQuotaRemaining()) {
            return false;
        }

        return true;
    }

    /**
     * Vérifie le quota de messages.
     */
    private function checkMessageQuota(User $editor, AgentDeployment $deployment): bool
    {
        // Vérifier le quota de l'éditeur
        if (!$editor->hasMessageQuotaRemaining()) {
            return false;
        }

        // Vérifier le quota du déploiement
        if (!$deployment->hasMessageQuotaRemaining()) {
            return false;
        }

        return true;
    }

    /**
     * Réponse 429 Quota Exceeded.
     */
    private function quotaExceeded(string $type): Response
    {
        $message = match ($type) {
            'session' => 'Quota de sessions mensuel atteint',
            'message' => 'Quota de messages mensuel atteint',
            default => 'Quota atteint',
        };

        return response()->json([
            'error' => 'quota_exceeded',
            'quota_type' => $type,
            'message' => $message,
        ], 429);
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
