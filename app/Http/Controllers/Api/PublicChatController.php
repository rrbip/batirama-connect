<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $session = $accessToken->session;
        $agent = $session->agent;

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->uuid,
                'agent' => [
                    'name' => $agent->name,
                    'description' => $agent->description,
                    'avatar_url' => $agent->avatar_url,
                ],
                'expires_at' => $accessToken->expires_at->toIso8601String(),
                'uses_remaining' => $accessToken->max_uses - $accessToken->uses_count,
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

        if (!$accessToken || $accessToken->isExpired() || $accessToken->isExhausted()) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Lien invalide ou expiré',
            ], 410);
        }

        // Incrémenter le compteur
        $accessToken->increment('uses_count');

        $session = $accessToken->session;
        $agent = $session->agent;

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
                    'avatar_url' => $agent->avatar_url,
                ],
            ],
        ]);
    }

    /**
     * POST /c/{token}/message
     * Envoie un message dans la session
     */
    public function sendMessage(Request $request, string $token): JsonResponse
    {
        $accessToken = PublicAccessToken::where('token', $token)->first();

        if (!$accessToken || $accessToken->isExpired()) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Session invalide ou expirée',
            ], 410);
        }

        $message = $request->input('message');
        $attachments = $request->input('attachments', []);

        if (empty($message) && empty($attachments)) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'Message ou pièce jointe requis',
            ], 400);
        }

        $session = $accessToken->session;
        $agent = $session->agent;

        try {
            // Traiter le message via le dispatcher
            $response = $this->dispatcherService->dispatch(
                $message,
                $agent,
                null, // Pas d'utilisateur authentifié
                $session
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'response' => $response->content,
                    'session_id' => $session->uuid,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Public chat error', [
                'session_id' => $session->uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'server_error',
                'message' => 'Une erreur est survenue. Veuillez réessayer.',
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
                'message' => 'Session invalide',
            ], 404);
        }

        $session = $accessToken->session;

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
                'message_count' => $session->message_count,
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
                'message' => 'Session invalide',
            ], 404);
        }

        $session = $accessToken->session;
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

        if (!$accessToken || $accessToken->isExpired()) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Session invalide ou expirée',
            ], 410);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10 MB max
        ]);

        $file = $request->file('file');
        $session = $accessToken->session;

        // Stocker le fichier
        $path = $file->store("sessions/{$session->uuid}", 'public');

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
            'session_id' => $session->uuid,
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
}
