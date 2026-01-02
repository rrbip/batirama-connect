<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

/**
 * Service pour gérer la présence des agents de support via Soketi.
 *
 * Utilise le SDK Pusher pour vérifier les membres des canaux de présence.
 */
class PresenceService
{
    private Pusher $pusher;

    public function __construct()
    {
        $config = config('broadcasting.connections.pusher');

        $this->pusher = new Pusher(
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
    }

    /**
     * Vérifie si des agents de support sont connectés pour un agent IA donné.
     */
    public function hasConnectedAgents(int $agentId): bool
    {
        $count = $this->getConnectedAgentsCount($agentId);
        return $count > 0;
    }

    /**
     * Récupère le nombre d'agents connectés pour un agent IA donné.
     */
    public function getConnectedAgentsCount(int $agentId): int
    {
        $channelName = "presence-agent.{$agentId}.support";

        // Essayer le cache d'abord (mis à jour par les webhooks de présence)
        $cacheKey = "presence:agent:{$agentId}:count";
        $cachedCount = Cache::get($cacheKey);

        if ($cachedCount !== null) {
            return (int) $cachedCount;
        }

        // Sinon, interroger l'API Soketi
        return $this->fetchChannelMembersCount($channelName);
    }

    /**
     * Récupère la liste des agents connectés pour un agent IA donné.
     */
    public function getConnectedAgents(int $agentId): array
    {
        $channelName = "presence-agent.{$agentId}.support";

        Log::info('PresenceService: Fetching connected agents', [
            'agent_id' => $agentId,
            'channel' => $channelName,
        ]);

        $members = $this->fetchChannelMembers($channelName);

        Log::info('PresenceService: Connected agents result', [
            'agent_id' => $agentId,
            'members_count' => count($members),
            'members' => $members,
        ]);

        return $members;
    }

    /**
     * Met à jour le cache de présence (appelé par les webhooks).
     */
    public function updatePresenceCache(int $agentId, int $count): void
    {
        $cacheKey = "presence:agent:{$agentId}:count";
        Cache::put($cacheKey, $count, now()->addMinutes(5));
    }

    /**
     * Incrémente le compteur de présence (appelé quand un agent rejoint).
     */
    public function incrementPresence(int $agentId): void
    {
        $cacheKey = "presence:agent:{$agentId}:count";
        $current = Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $current + 1, now()->addMinutes(5));

        Log::info('Agent joined support channel', [
            'agent_id' => $agentId,
            'new_count' => $current + 1,
        ]);
    }

    /**
     * Décrémente le compteur de présence (appelé quand un agent quitte).
     */
    public function decrementPresence(int $agentId): void
    {
        $cacheKey = "presence:agent:{$agentId}:count";
        $current = Cache::get($cacheKey, 0);
        $newCount = max(0, $current - 1);
        Cache::put($cacheKey, $newCount, now()->addMinutes(5));

        Log::info('Agent left support channel', [
            'agent_id' => $agentId,
            'new_count' => $newCount,
        ]);
    }

    /**
     * Interroge l'API Soketi pour obtenir le nombre de membres d'un canal.
     */
    private function fetchChannelMembersCount(string $channelName): int
    {
        try {
            $members = $this->fetchChannelMembers($channelName);
            return count($members);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch channel members count', [
                'channel' => $channelName,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Interroge l'API Soketi via le SDK Pusher pour obtenir les membres d'un canal de présence.
     */
    private function fetchChannelMembers(string $channelName): array
    {
        try {
            // Utiliser le SDK Pusher pour l'API (gère l'authentification automatiquement)
            $response = $this->pusher->getPresenceUsers($channelName);

            Log::debug('PresenceService: Pusher SDK response', [
                'channel' => $channelName,
                'response' => $response,
            ]);

            // Le SDK retourne un objet avec la propriété 'users'
            if (is_object($response) && isset($response->users)) {
                $users = $response->users;

                Log::info('PresenceService: Soketi returned users', [
                    'channel' => $channelName,
                    'users_count' => count($users),
                    'raw_users' => $users,
                ]);

                // Convertir les objets en tableaux si nécessaire
                return array_map(function ($user) {
                    return is_object($user) ? (array) $user : $user;
                }, $users);
            }

            Log::info('PresenceService: No users in channel', [
                'channel' => $channelName,
            ]);

            return [];

        } catch (\Pusher\ApiErrorException $e) {
            // Canal inexistant = aucun membre (pas une erreur)
            if (str_contains($e->getMessage(), 'Unknown channel')) {
                Log::info('PresenceService: Channel not found (no members)', [
                    'channel' => $channelName,
                ]);
                return [];
            }

            Log::warning('PresenceService: Pusher API error', [
                'channel' => $channelName,
                'error' => $e->getMessage(),
            ]);
            return [];

        } catch (\Exception $e) {
            Log::warning('PresenceService: Failed to fetch channel members', [
                'channel' => $channelName,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Vérifie si un canal de présence existe et a des membres.
     */
    public function channelHasMembers(string $channelName): bool
    {
        try {
            $response = $this->pusher->getChannelInfo($channelName, ['info' => 'subscription_count']);

            if (is_object($response) && isset($response->subscription_count)) {
                return $response->subscription_count > 0;
            }

            return false;

        } catch (\Exception $e) {
            Log::warning('Failed to check channel existence', [
                'channel' => $channelName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Vérifie si l'utilisateur du chat standalone est connecté à une session.
     *
     * @param string $sessionUuid UUID de la session
     * @return bool True si au moins un guest est connecté au canal de présence
     */
    public function isSessionUserOnline(string $sessionUuid): bool
    {
        $channelName = "presence-chat.session.{$sessionUuid}";

        try {
            $members = $this->fetchChannelMembers($channelName);

            // Filtrer pour ne garder que les guests (pas les agents de support)
            // Soketi ne retourne que les IDs, pas les user_info complets
            // Les guests ont des IDs qui commencent par "guest_"
            $guests = array_filter($members, function ($member) {
                $userId = $member['id'] ?? ($member['user_id'] ?? '');
                return str_starts_with($userId, 'guest_');
            });

            $isOnline = count($guests) > 0;

            Log::info('PresenceService: Session user online check', [
                'session_uuid' => $sessionUuid,
                'channel' => $channelName,
                'total_members' => count($members),
                'guests_count' => count($guests),
                'is_online' => $isOnline,
            ]);

            return $isOnline;

        } catch (\Exception $e) {
            Log::warning('PresenceService: Failed to check session user presence', [
                'session_uuid' => $sessionUuid,
                'error' => $e->getMessage(),
            ]);
            // En cas d'erreur, considérer l'utilisateur comme hors ligne (envoyer l'email)
            return false;
        }
    }
}
