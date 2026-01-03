<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\Events\Support\SessionEscalated;
use App\Notifications\Support\SessionEscalatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Envoie des notifications Filament aux agents de support quand une session est escaladée.
 */
class NotifySupportAgentsOnEscalation implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(SessionEscalated $event): void
    {
        $session = $event->session;
        $agent = $session->agent;

        if (!$agent) {
            Log::warning('SessionEscalated: No agent found for session', [
                'session_id' => $session->id,
            ]);
            return;
        }

        // Récupérer les agents de support qui doivent être notifiés
        $supportUsers = $agent->supportUsers()
            ->wherePivot('notify_on_escalation', true)
            ->get();

        if ($supportUsers->isEmpty()) {
            // Si aucun agent avec notify_on_escalation, notifier tous les agents support
            $supportUsers = $agent->supportUsers;
        }

        Log::info('SessionEscalated: Sending notifications', [
            'session_id' => $session->id,
            'agent_id' => $agent->id,
            'support_users_count' => $supportUsers->count(),
        ]);

        // Envoyer la notification à chaque agent
        foreach ($supportUsers as $user) {
            $user->notify(new SessionEscalatedNotification($session));
        }

        // Notifier également les super-admins
        $superAdmins = \App\Models\User::whereHas('roles', function ($query) {
            $query->where('name', 'super-admin');
        })->get();

        foreach ($superAdmins as $admin) {
            // Éviter les doublons si l'admin est aussi agent support
            if (!$supportUsers->contains('id', $admin->id)) {
                $admin->notify(new SessionEscalatedNotification($session));
            }
        }
    }
}
