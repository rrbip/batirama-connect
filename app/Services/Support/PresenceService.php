<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service pour gérer la présence des agents de support via Soketi.
 *
 * Utilise l'API HTTP de Soketi pour vérifier les membres des canaux de présence.
 */
class PresenceService
{
    private string $host;
    private int $port;
    private string $appId;
    private string $key;
    private string $secret;

    public function __construct()
    {
        $this->host = config('broadcasting.connections.pusher.options.host', 'soketi');
        $this->port = (int) config('broadcasting.connections.pusher.options.port', 6001);
        $this->appId = config('broadcasting.connections.pusher.app_id', 'batirama-app');
        $this->key = config('broadcasting.connections.pusher.key', 'batirama-key');
        $this->secret = config('broadcasting.connections.pusher.secret', 'batirama-secret');
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
        return $this->fetchChannelMembers($channelName);
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
     * Interroge l'API Soketi pour obtenir les membres d'un canal de présence.
     *
     * @see https://docs.soketi.app/api/channels
     */
    private function fetchChannelMembers(string $channelName): array
    {
        try {
            $path = "/apps/{$this->appId}/channels/{$channelName}/users";
            $url = "http://{$this->host}:{$this->port}{$path}";

            // Générer la signature pour l'authentification
            $authTimestamp = time();
            $authVersion = '1.0';
            $bodyMd5 = md5('');

            $stringToSign = implode("\n", [
                'GET',
                $path,
                "auth_key={$this->key}&auth_timestamp={$authTimestamp}&auth_version={$authVersion}&body_md5={$bodyMd5}",
            ]);

            $authSignature = hash_hmac('sha256', $stringToSign, $this->secret);

            $response = Http::timeout(5)
                ->get($url, [
                    'auth_key' => $this->key,
                    'auth_timestamp' => $authTimestamp,
                    'auth_version' => $authVersion,
                    'body_md5' => $bodyMd5,
                    'auth_signature' => $authSignature,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['users'] ?? [];
            }

            // Canal inexistant = aucun membre
            if ($response->status() === 404) {
                return [];
            }

            Log::warning('Soketi API returned error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];

        } catch (\Exception $e) {
            Log::warning('Failed to fetch channel members from Soketi', [
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
            $path = "/apps/{$this->appId}/channels/{$channelName}";
            $url = "http://{$this->host}:{$this->port}{$path}";

            $authTimestamp = time();
            $authVersion = '1.0';
            $bodyMd5 = md5('');

            $stringToSign = implode("\n", [
                'GET',
                $path,
                "auth_key={$this->key}&auth_timestamp={$authTimestamp}&auth_version={$authVersion}&body_md5={$bodyMd5}",
            ]);

            $authSignature = hash_hmac('sha256', $stringToSign, $this->secret);

            $response = Http::timeout(5)
                ->get($url, [
                    'auth_key' => $this->key,
                    'auth_timestamp' => $authTimestamp,
                    'auth_version' => $authVersion,
                    'body_md5' => $bodyMd5,
                    'auth_signature' => $authSignature,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return ($data['subscription_count'] ?? 0) > 0;
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
}
