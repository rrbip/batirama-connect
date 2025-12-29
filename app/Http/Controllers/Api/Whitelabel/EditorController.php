<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Whitelabel;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentDeployment;
use App\Models\AllowedDomain;
use App\Models\Role;
use App\Models\User;
use App\Models\UserEditorLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Controller pour les endpoints de gestion éditeur.
 *
 * Ces endpoints permettent aux éditeurs (EBP, SAGE, etc.)
 * de gérer leurs déploiements et artisans liés.
 *
 * @OA\Tag(name="Editor Management", description="Endpoints de gestion pour les éditeurs")
 */
class EditorController extends Controller
{
    /**
     * Liste les déploiements de l'éditeur.
     */
    public function listDeployments(Request $request): JsonResponse
    {
        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $deployments = AgentDeployment::where('editor_id', $editor->id)
            ->with(['agent:id,name,slug', 'allowedDomains'])
            ->withCount('sessions')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'uuid' => $d->uuid,
                'name' => $d->name,
                'agent' => [
                    'id' => $d->agent->id,
                    'name' => $d->agent->name,
                    'slug' => $d->agent->slug,
                ],
                'deployment_key' => $d->deployment_key,
                'deployment_mode' => $d->deployment_mode,
                'domains' => $d->allowedDomains->pluck('domain'),
                'is_active' => $d->is_active,
                'sessions_count' => $d->sessions_count,
                'created_at' => $d->created_at->toIso8601String(),
            ]);

        return response()->json([
            'deployments' => $deployments,
            'total' => $deployments->count(),
        ]);
    }

    /**
     * Crée un nouveau déploiement.
     */
    public function createDeployment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => 'required|integer|exists:agents,id',
            'name' => 'required|string|max:100',
            'deployment_mode' => 'nullable|in:shared,dedicated',
            'allowed_domains' => 'required|array|min:1',
            'allowed_domains.*' => 'required|string|max:255',
            'branding' => 'nullable|array',
            'branding.chat_title' => 'nullable|string|max:100',
            'branding.welcome_message' => 'nullable|string|max:500',
            'branding.primary_color' => 'nullable|string|max:20',
            'max_sessions_day' => 'nullable|integer|min:1',
            'rate_limit_per_ip' => 'nullable|integer|min:10|max:1000',
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        // Vérifier que l'agent est whitelabel enabled
        $agent = Agent::find($validated['agent_id']);
        if (!$agent->isWhitelabelEnabled()) {
            return response()->json([
                'error' => 'agent_not_whitelabel',
                'message' => 'Cet agent n\'est pas disponible en whitelabel',
            ], 400);
        }

        // Vérifier le quota de déploiements
        $currentCount = AgentDeployment::where('editor_id', $editor->id)->count();
        if ($editor->max_deployments && $currentCount >= $editor->max_deployments) {
            return response()->json([
                'error' => 'quota_exceeded',
                'message' => 'Quota de déploiements atteint',
            ], 429);
        }

        // Vérifier l'unicité du nom pour cet éditeur/agent
        $exists = AgentDeployment::where('editor_id', $editor->id)
            ->where('agent_id', $validated['agent_id'])
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'duplicate_name',
                'message' => 'Un déploiement avec ce nom existe déjà pour cet agent',
            ], 409);
        }

        $deployment = DB::transaction(function () use ($validated, $editor) {
            $deployment = AgentDeployment::create([
                'uuid' => (string) Str::uuid(),
                'agent_id' => $validated['agent_id'],
                'editor_id' => $editor->id,
                'name' => $validated['name'],
                'deployment_mode' => $validated['deployment_mode'] ?? 'shared',
                'branding' => $validated['branding'] ?? null,
                'max_sessions_day' => $validated['max_sessions_day'] ?? null,
                'rate_limit_per_ip' => $validated['rate_limit_per_ip'] ?? 60,
                'is_active' => true,
            ]);

            // Créer les domaines autorisés
            foreach ($validated['allowed_domains'] as $domain) {
                AllowedDomain::create([
                    'deployment_id' => $deployment->id,
                    'domain' => $domain,
                    'is_active' => true,
                ]);
            }

            return $deployment;
        });

        return response()->json([
            'id' => $deployment->id,
            'uuid' => $deployment->uuid,
            'deployment_key' => $deployment->deployment_key,
            'created_at' => $deployment->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * Met à jour un déploiement.
     */
    public function updateDeployment(Request $request, int $deploymentId): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'allowed_domains' => 'nullable|array',
            'allowed_domains.*' => 'required|string|max:255',
            'branding' => 'nullable|array',
            'max_sessions_day' => 'nullable|integer|min:1',
            'rate_limit_per_ip' => 'nullable|integer|min:10|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $deployment = AgentDeployment::where('id', $deploymentId)
            ->where('editor_id', $editor->id)
            ->first();

        if (!$deployment) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Déploiement non trouvé',
            ], 404);
        }

        DB::transaction(function () use ($deployment, $validated) {
            // Mettre à jour les champs
            $deployment->update(array_filter([
                'name' => $validated['name'] ?? null,
                'branding' => $validated['branding'] ?? null,
                'max_sessions_day' => $validated['max_sessions_day'] ?? null,
                'rate_limit_per_ip' => $validated['rate_limit_per_ip'] ?? null,
                'is_active' => $validated['is_active'] ?? null,
            ], fn ($v) => $v !== null));

            // Mettre à jour les domaines si fournis
            if (isset($validated['allowed_domains'])) {
                // Supprimer les anciens domaines
                $deployment->allowedDomains()->delete();

                // Créer les nouveaux
                foreach ($validated['allowed_domains'] as $domain) {
                    AllowedDomain::create([
                        'deployment_id' => $deployment->id,
                        'domain' => $domain,
                        'is_active' => true,
                    ]);
                }
            }
        });

        return response()->json([
            'id' => $deployment->id,
            'uuid' => $deployment->uuid,
            'updated_at' => $deployment->fresh()->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Liste les artisans liés à l'éditeur.
     */
    public function listArtisans(Request $request): JsonResponse
    {
        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $links = UserEditorLink::where('editor_id', $editor->id)
            ->with(['artisan:id,name,email,company_name'])
            ->withCount('sessions')
            ->orderBy('linked_at', 'desc')
            ->get()
            ->map(fn ($link) => [
                'link_id' => $link->id,
                'external_id' => $link->external_id,
                'artisan' => [
                    'id' => $link->artisan->id,
                    'name' => $link->artisan->name,
                    'email' => $link->artisan->email,
                    'company_name' => $link->artisan->company_name,
                ],
                'is_active' => $link->is_active,
                'sessions_count' => $link->sessions_count,
                'linked_at' => $link->linked_at->toIso8601String(),
            ]);

        return response()->json([
            'artisans' => $links,
            'total' => $links->count(),
        ]);
    }

    /**
     * Lie un artisan existant à l'éditeur.
     */
    public function linkArtisan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'artisan_email' => 'required|email|max:255',
            'external_id' => 'required|string|max:100',
            'branding' => 'nullable|array',
            'permissions' => 'nullable|array',
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        // Trouver l'artisan par email
        $artisan = User::where('email', $validated['artisan_email'])
            ->whereHas('roles', fn ($q) => $q->where('slug', 'artisan'))
            ->first();

        if (!$artisan) {
            return response()->json([
                'error' => 'artisan_not_found',
                'message' => 'Aucun artisan trouvé avec cet email',
            ], 404);
        }

        // Vérifier que le lien n'existe pas déjà
        $existingLink = UserEditorLink::where('artisan_id', $artisan->id)
            ->where('editor_id', $editor->id)
            ->first();

        if ($existingLink) {
            return response()->json([
                'error' => 'already_linked',
                'message' => 'Cet artisan est déjà lié à votre compte',
                'link_id' => $existingLink->id,
            ], 409);
        }

        // Vérifier l'unicité de l'external_id
        $externalIdExists = UserEditorLink::where('editor_id', $editor->id)
            ->where('external_id', $validated['external_id'])
            ->exists();

        if ($externalIdExists) {
            return response()->json([
                'error' => 'duplicate_external_id',
                'message' => 'Cet ID externe est déjà utilisé',
            ], 409);
        }

        $link = UserEditorLink::create([
            'artisan_id' => $artisan->id,
            'editor_id' => $editor->id,
            'external_id' => $validated['external_id'],
            'branding' => $validated['branding'] ?? null,
            'permissions' => $validated['permissions'] ?? null,
            'is_active' => true,
            'linked_at' => now(),
        ]);

        return response()->json([
            'link_id' => $link->id,
            'artisan_id' => $artisan->id,
            'external_id' => $link->external_id,
            'linked_at' => $link->linked_at->toIso8601String(),
        ], 201);
    }

    /**
     * Crée un artisan et le lie à l'éditeur.
     */
    public function createAndLinkArtisan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255|unique:users,email',
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'external_id' => 'required|string|max:100',
            'branding' => 'nullable|array',
            'permissions' => 'nullable|array',
            'send_invitation' => 'nullable|boolean',
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        // Vérifier l'unicité de l'external_id
        $externalIdExists = UserEditorLink::where('editor_id', $editor->id)
            ->where('external_id', $validated['external_id'])
            ->exists();

        if ($externalIdExists) {
            return response()->json([
                'error' => 'duplicate_external_id',
                'message' => 'Cet ID externe est déjà utilisé',
            ], 409);
        }

        $result = DB::transaction(function () use ($validated, $editor) {
            // Créer l'artisan
            $artisan = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'company_name' => $validated['company_name'] ?? null,
                'password' => null, // Sera défini lors de l'invitation
                'marketplace_enabled' => true,
            ]);

            // Assigner le rôle artisan
            $artisanRole = Role::where('slug', 'artisan')->first();
            if ($artisanRole) {
                $artisan->roles()->attach($artisanRole->id);
            }

            // Créer le lien
            $link = UserEditorLink::create([
                'artisan_id' => $artisan->id,
                'editor_id' => $editor->id,
                'external_id' => $validated['external_id'],
                'branding' => $validated['branding'] ?? null,
                'permissions' => $validated['permissions'] ?? null,
                'is_active' => true,
                'linked_at' => now(),
            ]);

            return [
                'artisan' => $artisan,
                'link' => $link,
            ];
        });

        // TODO: Envoyer l'invitation si demandé
        if ($validated['send_invitation'] ?? false) {
            // Mail::to($result['artisan'])->send(new ArtisanInvitation(...));
        }

        return response()->json([
            'artisan_id' => $result['artisan']->id,
            'link_id' => $result['link']->id,
            'external_id' => $result['link']->external_id,
            'linked_at' => $result['link']->linked_at->toIso8601String(),
        ], 201);
    }

    /**
     * Crée un lien de session pour un artisan.
     */
    public function createSessionLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deployment_key' => 'required|string',
            'external_id' => 'required|string|max:100',
            'context' => 'nullable|array',
            'expires_in' => 'nullable|integer|min:300|max:86400', // 5min - 24h
        ]);

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        // Trouver le déploiement
        $deployment = AgentDeployment::where('deployment_key', $validated['deployment_key'])
            ->where('editor_id', $editor->id)
            ->where('is_active', true)
            ->first();

        if (!$deployment) {
            return response()->json([
                'error' => 'deployment_not_found',
                'message' => 'Déploiement non trouvé ou inactif',
            ], 404);
        }

        // Trouver le lien artisan
        $editorLink = UserEditorLink::where('editor_id', $editor->id)
            ->where('external_id', $validated['external_id'])
            ->where('is_active', true)
            ->first();

        if (!$editorLink) {
            return response()->json([
                'error' => 'artisan_not_found',
                'message' => "Aucun artisan avec l'ID externe: {$validated['external_id']}",
            ], 404);
        }

        // Générer un token de session signé
        $expiresIn = $validated['expires_in'] ?? 3600; // 1h par défaut
        $expiresAt = now()->addSeconds($expiresIn);

        $tokenData = [
            'deployment_id' => $deployment->id,
            'editor_link_id' => $editorLink->id,
            'context' => $validated['context'] ?? [],
            'expires_at' => $expiresAt->timestamp,
        ];

        // Signer le token avec HMAC
        $token = base64_encode(json_encode($tokenData));
        $signature = hash_hmac('sha256', $token, config('app.key'));
        $signedToken = "{$token}.{$signature}";

        // URL de la page standalone
        $url = url("/s/{$signedToken}");

        return response()->json([
            'url' => $url,
            'session_token' => $signedToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }

    /**
     * Récupère les statistiques de l'éditeur.
     */
    public function getStats(Request $request): JsonResponse
    {
        $period = $request->query('period', 'month');

        /** @var User $editor */
        $editor = $request->attributes->get('editor');

        $startDate = match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfMonth(),
        };

        // Compter les sessions dans la période
        $sessionsCount = \App\Models\AiSession::whereHas('deployment', fn ($q) => $q->where('editor_id', $editor->id))
            ->where('created_at', '>=', $startDate)
            ->count();

        // Compter les messages dans la période
        $messagesCount = \App\Models\AiMessage::whereHas('session.deployment', fn ($q) => $q->where('editor_id', $editor->id))
            ->where('created_at', '>=', $startDate)
            ->count();

        // Compter les artisans uniques
        $uniqueArtisans = UserEditorLink::where('editor_id', $editor->id)
            ->where('is_active', true)
            ->count();

        return response()->json([
            'period' => $period,
            'sessions_count' => $sessionsCount,
            'messages_count' => $messagesCount,
            'unique_artisans' => $uniqueArtisans,
            'quotas' => [
                'sessions_used' => $editor->current_month_sessions ?? 0,
                'sessions_max' => $editor->max_sessions_month,
                'messages_used' => $editor->current_month_messages ?? 0,
                'messages_max' => $editor->max_messages_month,
                'deployments_used' => AgentDeployment::where('editor_id', $editor->id)->count(),
                'deployments_max' => $editor->max_deployments,
            ],
        ]);
    }
}
