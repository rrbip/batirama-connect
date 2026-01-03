<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

/**
 * Gère l'authentification broadcast pour les utilisateurs anonymes du chat standalone.
 * Permet aux guests de rejoindre des canaux de présence.
 */
class GuestBroadcastAuthController extends Controller
{
    /**
     * POST /broadcasting/auth/guest
     * Authentifie un utilisateur guest pour un canal de présence.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');
        $sessionUuid = $request->input('session_uuid');

        if (!$socketId || !$channelName) {
            return response()->json(['error' => 'Missing socket_id or channel_name'], 400);
        }

        // Vérifier que c'est un canal de présence pour une session de chat
        if (!preg_match('/^presence-chat\.session\.(.+)$/', $channelName, $matches)) {
            return response()->json(['error' => 'Invalid channel'], 403);
        }

        $channelSessionUuid = $matches[1];

        // Vérifier que la session existe
        $session = AiSession::where('uuid', $channelSessionUuid)->first();

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        // Générer un ID unique pour ce guest basé sur la session
        $guestId = 'guest_' . substr(md5($channelSessionUuid . $socketId), 0, 12);
        $guestName = $session->user_email ? explode('@', $session->user_email)[0] : 'Visiteur';

        // Créer les données utilisateur pour le canal de présence
        $userData = [
            'user_id' => $guestId,
            'user_info' => [
                'id' => $guestId,
                'name' => $guestName,
                'type' => 'guest',
                'session_uuid' => $channelSessionUuid,
            ],
        ];

        // Signer l'auth avec Pusher
        $config = config('broadcasting.connections.pusher');
        $pusher = new Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            [
                'host' => $config['options']['host'] ?? '127.0.0.1',
                'port' => $config['options']['port'] ?? 6001,
                'scheme' => $config['options']['scheme'] ?? 'http',
                'useTLS' => $config['options']['useTLS'] ?? false,
            ]
        );

        $auth = $pusher->authorizePresenceChannel($channelName, $socketId, $guestId, $userData['user_info']);

        Log::debug('GuestBroadcastAuth: Authorized guest for presence channel', [
            'socket_id' => $socketId,
            'channel' => $channelName,
            'guest_id' => $guestId,
            'session_uuid' => $channelSessionUuid,
        ]);

        return response()->json(json_decode($auth, true));
    }
}
