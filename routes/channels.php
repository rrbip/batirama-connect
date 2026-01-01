<?php

use App\Models\AiSession;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/**
 * Canal privé pour un utilisateur spécifique.
 * Utilisé pour les notifications personnelles.
 */
Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

/**
 * Canal privé pour les notifications Laravel/Livewire.
 * Format standard utilisé par Laravel Echo et Filament.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

/**
 * Canal privé pour le support d'un agent IA spécifique.
 * Seuls les agents de support assignés peuvent écouter.
 */
Broadcast::channel('agent.{agentId}.support', function (User $user, int $agentId) {
    // Super-admin et admin peuvent tout voir
    if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
        return true;
    }

    // Vérifie si l'utilisateur est assigné comme agent de support pour cet agent IA
    $agent = \App\Models\Agent::find($agentId);
    if (!$agent) {
        return false;
    }

    return $agent->userCanHandleSupport($user);
});

/**
 * Canal de PRÉSENCE pour les agents de support d'un agent IA.
 * Utilisé pour tracker en temps réel quels admins sont connectés.
 *
 * Retourne les infos utilisateur pour le suivi de présence.
 */
Broadcast::channel('presence-agent.{agentId}.support', function (User $user, int $agentId) {
    // Super-admin et admin peuvent rejoindre
    if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->roles->first()?->name ?? 'user',
        ];
    }

    // Vérifie si l'utilisateur est assigné comme agent de support
    $agent = \App\Models\Agent::find($agentId);
    if (!$agent) {
        return false;
    }

    if ($agent->userCanHandleSupport($user)) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'support-agent',
        ];
    }

    return false;
});

/**
 * Canal privé pour une session spécifique.
 * Utilisé pour la communication en temps réel user <-> agent.
 */
Broadcast::channel('session.{uuid}', function (User $user, string $uuid) {
    $session = AiSession::where('uuid', $uuid)->first();

    if (!$session) {
        return false;
    }

    // L'utilisateur de la session peut écouter
    if ($session->user_id === $user->id) {
        return true;
    }

    // L'agent de support assigné peut écouter
    if ($session->support_agent_id === $user->id) {
        return true;
    }

    // Super-admin et admin peuvent tout voir
    if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
        return true;
    }

    // Vérifie si l'utilisateur peut gérer le support pour cet agent IA
    return $session->agent?->userCanHandleSupport($user) ?? false;
});
