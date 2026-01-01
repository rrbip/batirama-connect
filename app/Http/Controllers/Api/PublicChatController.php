<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAiMessageJob;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\PublicAccessToken;
use App\Services\AI\DispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicChatController extends Controller
{
    public function __construct(
        private DispatcherService $dispatcherService
    ) {}

    /**
     * GET /c/{token}
     * Page d'accueil du chat public (info de la session)
     */
    public function show(string $token): JsonResponse
    {
        $accessToken = PublicAccessToken::where('token', $token)->first();

        if (!$accessToken) {
            return response()->json([
                'error' => 'token_not_found',
                'message' => 'Lien invalide',
            ], 404);
        }

        if ($accessToken->isExpired()) {
            return response()->json([
                'error' => 'token_expired',
                'message' => 'Ce lien a expiré',
            ], 410);
        }

        if ($accessToken->isExhausted()) {
            return response()->json([
                'error' => 'token_exhausted',
                'message' => 'Ce lien a déjà été utilisé',
            ], 410);
        }

        // Charger l'agent directement depuis le token (session peut ne pas exister)
        $agent = $accessToken->agent;

        if (!$agent) {
            return response()->json([
                'error' => 'agent_not_found',
                'message' => 'Agent non trouvé',
            ], 404);
        }

        $session = $accessToken->session;
        $usesCount = $accessToken->use_count ?? 0;
        $maxUses = $accessToken->max_uses ?? 1;

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session?->uuid,
                'has_session' => $session !== null,
                'agent' => [
                    'name' => $agent->name,
                    'description' => $agent->description,
                    'avatar_url' => $agent->avatar_url ?? null,
                ],
                'expires_at' => $accessToken->expires_at?->toIso8601String(),
                'uses_remaining' => max(0, $maxUses - $usesCount),
            ],
        ]);
    }

    /**
     * POST /c/{token}/start
     * Démarre la session (incrémente le compteur d'utilisation)
     */
    public function start(string $token): JsonResponse
    {
        $accessToken = PublicAccessToken::where('token', $token)->first();

        if (!$accessToken) {
            return response()->json([
                'error' => 'token_not_found',
                'message' => 'Lien invalide',
            ], 404);
        }

        if ($accessToken->isExpired()) {
            return response()->json([
                'error' => 'token_expired',
                'message' => 'Lien expiré',
            ], 410);
        }

        if ($accessToken->isExhausted()) {
            return response()->json([
                'error' => 'token_exhausted',
                'message' => 'Lien déjà utilisé',
            ], 410);
        }

        // Charger l'agent
        $agent = $accessToken->agent;

        if (!$agent) {
            return response()->json([
                'error' => 'agent_not_found',
                'message' => 'Agent non trouvé',
            ], 404);
        }

        // Créer une session si elle n'existe pas encore
        $session = $accessToken->session;

        if (!$session) {
            $session = $this->dispatcherService->createSession($agent);

            // Lier la session au token
            $accessToken->update(['session_id' => $session->id]);
            $accessToken->refresh();

            // Si un email client est pré-configuré, l'ajouter à la session
            $clientEmail = $accessToken->client_info['email'] ?? null;
            if ($clientEmail) {
                $session->update(['user_email' => $clientEmail]);
            }
        }

        // Incrémenter le compteur
        $usesField = $accessToken->getConnection()->getSchemaBuilder()->hasColumn('public_access_tokens', 'uses_count')
            ? 'uses_count'
            : 'use_count';
        $accessToken->increment($usesField);

        // Récupérer le message d'accueil de l'agent
        $welcomeMessage = $agent->welcome_message ?? "Bonjour ! Je suis {$agent->name}. Comment puis-je vous aider ?";

        Log::info('Public session started', [
            'session_id' => $session->uuid,
            'token' => substr($token, 0, 8) . '...',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->uuid,
                'welcome_message' => $welcomeMessage,
                'agent' => [
                    'name' => $agent->name,
                    'avatar_url' => $agent->avatar_url ?? null,
                ],
            ],
        ]);
    }

    /**
     * POST /c/{token}/message
     * Envoie un message dans la session (mode asynchrone)
     *
     * Le message est mis en file d'attente et traité par un worker.
     * Utilisez GET /messages/{uuid}/status pour récupérer le résultat.
     */
    public function sendMessage(Request $request, string $token): JsonResponse
    {
        $accessToken = PublicAccessToken::where('token', $token)->first();

        if (!$accessToken) {
            return response()->json([
                'error' => 'token_not_found',
                'message' => 'Token invalide',
            ], 404);
        }

        if ($accessToken->isExpired()) {
            return response()->json([
                'error' => 'token_expired',
                'message' => 'Session expirée',
            ], 410);
        }

        $message = $request->input('message');
        $attachments = $request->input('attachments', []);
        $async = $request->boolean('async', true); // Par défaut async

        if (empty($message) && empty($attachments)) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'Message ou pièce jointe requis',
            ], 400);
        }

        // Charger l'agent
        $agent = $accessToken->agent;

        if (!$agent) {
            return response()->json([
                'error' => 'agent_not_found',
                'message' => 'Agent non trouvé',
            ], 404);
        }

        // La session doit exister (créée via /start)
        $session = $accessToken->session;

        if (!$session) {
            // Auto-créer la session si elle n'existe pas (compatibilité)
            $session = $this->dispatcherService->createSession($agent, null, 'public_chat');
            $accessToken->update(['session_id' => $session->id]);
            $accessToken->refresh();
        }

        try {
            // Mode asynchrone (par défaut) - retourne immédiatement
            if ($async) {
                $aiMessage = $this->dispatcherService->dispatchAsync(
                    $message,
                    $agent,
                    null,
                    $session,
                    'public_chat'
                );

                return response()->json([
                    'success' => true,
                    'async' => true,
                    'data' => [
                        'message_id' => $aiMessage->uuid,
                        'status' => $aiMessage->processing_status,
                        'session_id' => $session->uuid,
                        'poll_url' => url("/api/messages/{$aiMessage->uuid}/status"),
                    ],
                ]);
            }

            // Mode synchrone (rétrocompatibilité) - attend la réponse
            $response = $this->dispatcherService->dispatch(
                $message,
                $agent,
                null,
                $session
            );

            return response()->json([
                'success' => true,
                'async' => false,
                'data' => [
                    'response' => $response->content,
                    'session_id' => $session->uuid,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Public chat error', [
                'session_id' => $session->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'server_error',
                'message' => 'Une erreur est survenue. Veuillez réessayer.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST /c/{token}/end
     * Termine la session
     */
    public function end(Request $request, string $token): JsonResponse
    {
        $accessToken = PublicAccessToken::where('token', $token)->first();

        if (!$accessToken) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Token invalide',
            ], 404);
        }

        $session = $accessToken->session;

        if (!$session) {
            return response()->json([
                'error' => 'no_session',
                'message' => 'Aucune session active',
            ], 404);
        }

        // Terminer la session
        $this->dispatcherService->endSession($session);

        Log::info('Public session ended', [
            'session_id' => $session->uuid,
            'message_count' => $session->message_count,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->uuid,
                'message_count' => $session->message_count ?? 0,
                'ended_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /c/{token}/history
     * Récupère l'historique de la conversation
     */
    public function history(string $token): JsonResponse
    {
        $accessToken = PublicAccessToken::where('token', $token)->first();

        if (!$accessToken) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Token invalide',
            ], 404);
        }

        $session = $accessToken->session;

        if (!$session) {
            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => null,
                    'messages' => [],
                ],
            ]);
        }

        $history = $this->dispatcherService->getSessionHistory($session);

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->uuid,
                'messages' => $history,
            ],
        ]);
    }

    /**
     * POST /c/{token}/upload
     * Upload une pièce jointe
     */
    public function upload(Request $request, string $token): JsonResponse
    {
        $accessToken = PublicAccessToken::where('token', $token)->first();

        if (!$accessToken) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Token invalide',
            ], 404);
        }

        if ($accessToken->isExpired()) {
            return response()->json([
                'error' => 'token_expired',
                'message' => 'Session expirée',
            ], 410);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10 MB max
        ]);

        $file = $request->file('file');
        $session = $accessToken->session;

        // Si pas de session, utiliser un identifiant temporaire basé sur le token
        $sessionIdentifier = $session?->uuid ?? 'temp_' . substr($token, 0, 16);

        // Stocker le fichier
        $path = $file->store("sessions/{$sessionIdentifier}", 'public');

        $attachment = [
            'id' => 'att_' . uniqid(),
            'name' => $file->getClientOriginalName(),
            'type' => $this->getAttachmentType($file->getMimeType()),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'url' => asset('storage/' . $path),
            'path' => $path,
        ];

        Log::info('File uploaded', [
            'session_id' => $sessionIdentifier,
            'file' => $attachment['name'],
            'size' => $attachment['size'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $attachment,
        ]);
    }

    /**
     * Détermine le type de pièce jointe
     */
    private function getAttachmentType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            $mimeType === 'application/pdf' => 'pdf',
            default => 'document',
        };
    }

    /**
     * GET /messages/{uuid}/status
     * Récupère le statut d'un message en cours de traitement
     */
    public function messageStatus(string $uuid): JsonResponse
    {
        $message = AiMessage::where('uuid', $uuid)->first();

        if (!$message) {
            return response()->json([
                'error' => 'message_not_found',
                'message' => 'Message non trouvé',
            ], 404);
        }

        // Calculer la position dans la queue si pending/queued
        $queuePosition = null;
        if (in_array($message->processing_status, [AiMessage::STATUS_PENDING, AiMessage::STATUS_QUEUED])) {
            $queuePosition = AiMessage::where('role', 'assistant')
                ->whereIn('processing_status', [AiMessage::STATUS_PENDING, AiMessage::STATUS_QUEUED])
                ->where(function ($query) use ($message) {
                    $query->where('queued_at', '<', $message->queued_at ?? $message->created_at)
                        ->orWhere(function ($q) use ($message) {
                            $q->where('queued_at', '=', $message->queued_at ?? $message->created_at)
                              ->where('id', '<', $message->id);
                        });
                })
                ->count() + 1;
        }

        $response = [
            'success' => true,
            'data' => [
                'message_id' => $message->uuid,
                'status' => $message->processing_status,
                'queue_position' => $queuePosition,
                'queued_at' => $message->queued_at?->toIso8601String(),
                'processing_started_at' => $message->processing_started_at?->toIso8601String(),
                'processing_completed_at' => $message->processing_completed_at?->toIso8601String(),
                'retry_count' => $message->retry_count,
            ],
        ];

        // Ajouter le contenu si terminé avec succès
        if ($message->processing_status === AiMessage::STATUS_COMPLETED) {
            $response['data']['content'] = $message->content;
            $response['data']['model'] = $message->model_used;
            $response['data']['generation_time_ms'] = $message->generation_time_ms;
            $response['data']['tokens_prompt'] = $message->tokens_prompt;
            $response['data']['tokens_completion'] = $message->tokens_completion;
        }

        // Ajouter l'erreur si échec
        if ($message->processing_status === AiMessage::STATUS_FAILED) {
            $response['data']['error'] = $message->processing_error;
            $response['data']['can_retry'] = true;
            $response['data']['retry_url'] = url("/api/messages/{$message->uuid}/retry");
        }

        return response()->json($response);
    }

    /**
     * POST /messages/{uuid}/retry
     * Relance un message en échec
     */
    public function retryMessage(string $uuid): JsonResponse
    {
        $message = AiMessage::where('uuid', $uuid)
            ->where('processing_status', AiMessage::STATUS_FAILED)
            ->first();

        if (!$message) {
            return response()->json([
                'error' => 'message_not_found',
                'message' => 'Message non trouvé ou pas en échec',
            ], 404);
        }

        // Récupérer le message utilisateur original (le précédent dans la session)
        $userMessage = AiMessage::where('session_id', $message->session_id)
            ->where('role', 'user')
            ->where('created_at', '<', $message->created_at)
            ->orderByDesc('created_at')
            ->first();

        if (!$userMessage) {
            return response()->json([
                'error' => 'user_message_not_found',
                'message' => 'Message utilisateur original non trouvé',
            ], 404);
        }

        // Réinitialiser et relancer
        $message->resetForRetry();
        $message->increment('retry_count');

        // Dispatcher le nouveau job
        $job = new ProcessAiMessageJob($message, $userMessage->content);
        dispatch($job);

        $message->markAsQueued();

        Log::info('Message retry dispatched', [
            'message_id' => $message->id,
            'message_uuid' => $message->uuid,
            'retry_count' => $message->retry_count,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'message_id' => $message->uuid,
                'status' => $message->processing_status,
                'retry_count' => $message->retry_count,
                'poll_url' => url("/api/messages/{$message->uuid}/status"),
            ],
        ]);
    }
}
