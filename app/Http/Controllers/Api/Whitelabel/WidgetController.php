<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Whitelabel;

use App\Events\Whitelabel\FileUploaded;
use App\Events\Whitelabel\SessionStarted;
use App\Http\Controllers\Controller;
use App\Models\AgentDeployment;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\User;
use App\Models\UserEditorLink;
use App\Services\Upload\FileUploadService;
use App\Services\Whitelabel\BrandingResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Controller pour les endpoints widget whitelabel.
 *
 * Ces endpoints sont utilisés par le widget JavaScript intégré
 * dans les applications tierces (EBP, SAGE, etc.).
 *
 * @OA\Tag(name="Whitelabel Widget", description="Endpoints pour le widget de chat intégrable")
 */
class WidgetController extends Controller
{
    public function __construct(
        private readonly BrandingResolver $brandingResolver,
        private readonly FileUploadService $fileUploadService
    ) {}

    /**
     * Initialise une nouvelle session de chat.
     *
     * @OA\Post(
     *     path="/api/whitelabel/sessions",
     *     operationId="initWhitelabelSession",
     *     tags={"Whitelabel Widget"},
     *     security={{"deploymentKey": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/CreateSessionRequest")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Session créée",
     *         @OA\JsonContent(ref="#/components/schemas/SessionResponse")
     *     )
     * )
     */
    public function init(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'external_id' => 'required|string|max:100',
            'particulier_email' => 'nullable|email|max:255',
            'particulier_name' => 'nullable|string|max:255',
            'context' => 'nullable|array',
        ]);

        /** @var AgentDeployment $deployment */
        $deployment = $request->attributes->get('deployment');

        // Trouver le lien artisan par external_id
        $editorLink = UserEditorLink::where('editor_id', $deployment->editor_id)
            ->where('external_id', $validated['external_id'])
            ->where('is_active', true)
            ->first();

        if (!$editorLink) {
            return response()->json([
                'error' => 'artisan_not_found',
                'message' => "Aucun artisan trouvé avec l'ID externe: {$validated['external_id']}",
            ], 404);
        }

        // Créer ou récupérer le particulier
        $particulier = null;
        if (!empty($validated['particulier_email'])) {
            $particulier = $this->findOrCreateParticulier(
                $validated['particulier_email'],
                $validated['particulier_name'] ?? null
            );
        }

        // Créer la session
        $session = DB::transaction(function () use ($deployment, $editorLink, $particulier, $validated) {
            $session = AiSession::create([
                'uuid' => (string) Str::uuid(),
                'agent_id' => $deployment->agent_id,
                'user_id' => $editorLink->artisan_id, // L'artisan
                'deployment_id' => $deployment->id,
                'editor_link_id' => $editorLink->id,
                'particulier_id' => $particulier?->id,
                'status' => 'active',
                'external_context' => array_merge(
                    $validated['context'] ?? [],
                    [
                        'source' => 'whitelabel',
                        'editor_id' => $deployment->editor_id,
                        'external_id' => $validated['external_id'],
                    ]
                ),
            ]);

            // Incrémenter les compteurs
            $deployment->incrementSessionCount();
            $deployment->editor?->incrementSessionCount();

            return $session;
        });

        // Résoudre le branding
        $branding = $this->brandingResolver->resolveForDeployment($deployment, $editorLink);

        Log::info('Whitelabel session created', [
            'session_id' => $session->uuid,
            'deployment_id' => $deployment->id,
            'editor_link_id' => $editorLink->id,
            'artisan_id' => $editorLink->artisan_id,
        ]);

        // Dispatch event for webhooks
        SessionStarted::dispatch($session);

        return response()->json([
            'session_id' => $session->uuid,
            'status' => $session->status,
            'agent' => [
                'name' => $deployment->agent->name,
                'slug' => $deployment->agent->slug,
            ],
            'branding' => $branding,
            'created_at' => $session->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * Envoie un message et reçoit la réponse de l'IA.
     */
    public function sendMessage(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:10000',
            'attachments' => 'nullable|array',
            'attachments.*.type' => 'required_with:attachments|string',
            'attachments.*.url' => 'required_with:attachments|url',
        ]);

        /** @var AgentDeployment $deployment */
        $deployment = $request->attributes->get('deployment');

        // Trouver la session
        $session = AiSession::where('uuid', $sessionId)
            ->where('deployment_id', $deployment->id)
            ->where('status', 'active')
            ->first();

        if (!$session) {
            return response()->json([
                'error' => 'session_not_found',
                'message' => 'Session non trouvée ou fermée',
            ], 404);
        }

        // Créer le message utilisateur
        $userMessage = $session->messages()->create([
            'uuid' => (string) Str::uuid(),
            'role' => 'user',
            'content' => $validated['message'],
            'metadata' => [
                'attachments' => $validated['attachments'] ?? [],
                'ip' => $request->ip(),
            ],
        ]);

        // Incrémenter les compteurs
        $deployment->incrementMessageCount();
        $session->increment('message_count');

        // Dispatcher le job de génération de réponse IA
        // Pour l'instant, on utilise le service existant de chat
        try {
            $response = $this->generateAiResponse($session, $userMessage);

            return response()->json([
                'message_id' => $userMessage->uuid,
                'response' => [
                    'message_id' => $response->uuid,
                    'content' => $response->content,
                    'sources' => $response->metadata['sources'] ?? [],
                    'created_at' => $response->created_at->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Whitelabel message error', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'generation_failed',
                'message' => 'Erreur lors de la génération de la réponse',
                'message_id' => $userMessage->uuid,
            ], 500);
        }
    }

    /**
     * Récupère l'historique des messages d'une session.
     * Inclut les messages IA et les messages de support (système, agent).
     */
    public function getMessages(Request $request, string $sessionId): JsonResponse
    {
        /** @var AgentDeployment $deployment */
        $deployment = $request->attributes->get('deployment');

        $session = AiSession::where('uuid', $sessionId)
            ->where('deployment_id', $deployment->id)
            ->first();

        if (!$session) {
            return response()->json([
                'error' => 'session_not_found',
                'message' => 'Session non trouvée',
            ], 404);
        }

        $isHumanSupportActive = in_array($session->support_status, ['escalated', 'assigned']);

        // Messages IA (filtrer les non-validés si support humain actif)
        $aiMessages = $session->messages()
            ->when($isHumanSupportActive, function ($query) {
                // Si support actif, n'afficher que:
                // - Messages user
                // - Messages assistant validés ou appris
                $query->where(function ($q) {
                    $q->where('role', 'user')
                      ->orWhere(function ($q2) {
                          $q2->where('role', 'assistant')
                             ->whereIn('validation_status', ['validated', 'learned']);
                      });
                });
            })
            ->get()
            ->map(fn ($msg) => [
                'message_id' => $msg->uuid,
                'role' => $msg->role,
                'content' => $msg->corrected_content ?? $msg->content,
                'sources' => $msg->metadata['sources'] ?? [],
                'created_at' => $msg->created_at,
                'type' => 'ai',
            ]);

        // Messages de support (agent, system)
        $supportMessages = $session->supportMessages()
            ->get()
            ->map(fn ($msg) => [
                'message_id' => $msg->uuid,
                'role' => $msg->sender_type === 'agent' ? 'support' : 'system',
                'content' => $msg->content,
                'sender_name' => $msg->sender?->name ?? null,
                'created_at' => $msg->created_at,
                'type' => 'support',
            ]);

        // Fusionner et trier par date
        $allMessages = $aiMessages->concat($supportMessages)
            ->sortBy('created_at')
            ->values()
            ->map(fn ($msg) => [
                'message_id' => $msg['message_id'],
                'role' => $msg['role'],
                'content' => $msg['content'],
                'sender_name' => $msg['sender_name'] ?? null,
                'sources' => $msg['sources'] ?? [],
                'created_at' => $msg['created_at']->toIso8601String(),
            ]);

        return response()->json([
            'session_id' => $session->uuid,
            'messages' => $allMessages,
        ]);
    }

    /**
     * Récupère les détails d'une session.
     */
    public function getSession(Request $request, string $sessionId): JsonResponse
    {
        /** @var AgentDeployment $deployment */
        $deployment = $request->attributes->get('deployment');

        $session = AiSession::where('uuid', $sessionId)
            ->where('deployment_id', $deployment->id)
            ->with(['editorLink.artisan'])
            ->first();

        if (!$session) {
            return response()->json([
                'error' => 'session_not_found',
                'message' => 'Session non trouvée',
            ], 404);
        }

        $branding = $this->brandingResolver->resolve($session);

        return response()->json([
            'session_id' => $session->uuid,
            'status' => $session->status,
            'message_count' => $session->message_count,
            'branding' => $branding,
            'created_at' => $session->created_at->toIso8601String(),
        ]);
    }

    /**
     * Ferme une session.
     */
    public function closeSession(Request $request, string $sessionId): JsonResponse
    {
        /** @var AgentDeployment $deployment */
        $deployment = $request->attributes->get('deployment');

        $session = AiSession::where('uuid', $sessionId)
            ->where('deployment_id', $deployment->id)
            ->where('status', 'active')
            ->first();

        if (!$session) {
            return response()->json([
                'error' => 'session_not_found',
                'message' => 'Session non trouvée ou déjà fermée',
            ], 404);
        }

        $session->update(['status' => 'archived']);

        return response()->json([
            'session_id' => $session->uuid,
            'status' => 'archived',
            'closed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Récupère la configuration du déploiement.
     */
    public function getConfig(Request $request): JsonResponse
    {
        /** @var AgentDeployment $deployment */
        $deployment = $request->attributes->get('deployment');
        $agent = $deployment->agent;

        // Branding par défaut du déploiement (sans artisan spécifique)
        $branding = $this->brandingResolver->resolveForDeployment($deployment);

        return response()->json([
            'deployment_id' => $deployment->uuid,
            'agent' => [
                'name' => $agent->name,
                'slug' => $agent->slug,
                'description' => $agent->description,
            ],
            'branding' => $branding,
            'features' => [
                'attachments_enabled' => $agent->allow_attachments ?? false,
                'max_message_length' => 10000,
                'streaming_enabled' => true,
            ],
        ]);
    }

    /**
     * Upload un fichier pour une session.
     */
    public function uploadFile(Request $request, string $sessionId): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10 MB
        ]);

        /** @var AgentDeployment $deployment */
        $deployment = $request->attributes->get('deployment');

        // Trouver la session
        $session = AiSession::where('uuid', $sessionId)
            ->where('deployment_id', $deployment->id)
            ->where('status', 'active')
            ->first();

        if (!$session) {
            return response()->json([
                'error' => 'session_not_found',
                'message' => 'Session non trouvée ou fermée',
            ], 404);
        }

        try {
            $file = $this->fileUploadService->upload(
                $request->file('file'),
                $session
            );

            // Dispatch event for webhooks
            FileUploaded::dispatch($session, $file);

            Log::info('Whitelabel file uploaded', [
                'session_id' => $session->uuid,
                'file_id' => $file->uuid,
                'file_name' => $file->original_name,
            ]);

            return response()->json([
                'success' => true,
                'file' => $file->toApiArray(),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'validation_error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Whitelabel upload error', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'upload_failed',
                'message' => 'Erreur lors de l\'upload du fichier',
            ], 500);
        }
    }

    /**
     * Récupère les fichiers d'une session.
     */
    public function getFiles(Request $request, string $sessionId): JsonResponse
    {
        /** @var AgentDeployment $deployment */
        $deployment = $request->attributes->get('deployment');

        $session = AiSession::where('uuid', $sessionId)
            ->where('deployment_id', $deployment->id)
            ->first();

        if (!$session) {
            return response()->json([
                'error' => 'session_not_found',
                'message' => 'Session non trouvée',
            ], 404);
        }

        $files = $this->fileUploadService->getSessionFiles($session);

        return response()->json([
            'session_id' => $session->uuid,
            'files' => $files,
        ]);
    }

    /**
     * Trouve ou crée un particulier.
     */
    private function findOrCreateParticulier(string $email, ?string $name): User
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            return $user;
        }

        // Créer le particulier
        $user = User::create([
            'name' => $name ?? explode('@', $email)[0],
            'email' => $email,
            'password' => null, // Pas de mot de passe, connexion via lien
        ]);

        // Assigner le rôle particulier
        $particulierRole = \App\Models\Role::where('slug', 'particulier')->first();
        if ($particulierRole) {
            $user->roles()->attach($particulierRole->id);
        }

        return $user;
    }

    /**
     * Génère une réponse IA.
     *
     * Note: Cette méthode utilise le service de chat existant.
     * Pour une implémentation complète, elle devrait dispatcher un job async.
     */
    private function generateAiResponse(AiSession $session, AiMessage $userMessage): AiMessage
    {
        // Utiliser le service de chat existant
        $chatService = app(\App\Services\AiChatService::class);

        // Obtenir la réponse (synchrone pour l'instant)
        $response = $chatService->chat($session, $userMessage->content);

        // Créer le message de réponse
        $assistantMessage = $session->messages()->create([
            'uuid' => (string) Str::uuid(),
            'role' => 'assistant',
            'content' => $response['content'] ?? $response,
            'metadata' => [
                'sources' => $response['sources'] ?? [],
                'model' => $response['model'] ?? null,
                'tokens' => $response['tokens'] ?? null,
            ],
        ]);

        return $assistantMessage;
    }
}
