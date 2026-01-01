<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\Events\Support\NewSupportMessage;
use App\Notifications\Support\NewSupportMessageNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Envoie des notifications Filament quand un nouveau message arrive dans une session support.
 */
class NotifyOnNewSupportMessage implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(NewSupportMessage $event): void
    {
        $message = $event->message;
        $session = $message->session;

        // Ne notifier que pour les messages utilisateurs (pas les messages agents)
        if ($message->sender_type !== 'user') {
            return;
        }

        $agent = $session->agent;

        if (!$agent) {
            Log::warning('NewSupportMessage: No agent found for session', [
                'session_id' => $session->id,
                'message_id' => $message->id,
            ]);
            return;
        }

        // Si un agent support est assigné, le notifier en priorité
        if ($session->support_agent_id) {
            $supportAgent = $session->supportAgent;
            if ($supportAgent) {
                $supportAgent->notify(new NewSupportMessageNotification(
                    session: $session,
                    senderType: $message->sender_type,
                    messagePreview: $message->content
                ));
            }
            return;
        }

        // Sinon, notifier tous les agents de support avec notify_on_escalation
        $supportUsers = $agent->supportUsers()
            ->wherePivot('notify_on_escalation', true)
            ->get();

        if ($supportUsers->isEmpty()) {
            $supportUsers = $agent->supportUsers;
        }

        Log::info('NewSupportMessage: Sending notifications', [
            'session_id' => $session->id,
            'message_id' => $message->id,
            'support_users_count' => $supportUsers->count(),
        ]);

        foreach ($supportUsers as $user) {
            $user->notify(new NewSupportMessageNotification(
                session: $session,
                senderType: $message->sender_type,
                messagePreview: $message->content
            ));
        }
    }
}
