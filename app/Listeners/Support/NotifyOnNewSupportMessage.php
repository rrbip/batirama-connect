<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\Events\Support\NewSupportMessage;
use App\Mail\Support\NewMessageNotificationMail;
use App\Models\User;
use App\Notifications\Support\NewSupportMessageNotification;
use App\Services\Support\PresenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie des notifications Filament quand un nouveau message arrive dans une session support.
 * Si l'agent support n'est pas connecté au backoffice, un email lui est envoyé.
 */
class NotifyOnNewSupportMessage implements ShouldQueue
{
    public function __construct(
        protected PresenceService $presenceService
    ) {}

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

        // Récupérer les IDs des utilisateurs connectés au canal de présence
        $connectedUserIds = $this->getConnectedUserIds($agent->id);

        // Si un agent support est assigné, le notifier en priorité
        if ($session->support_agent_id) {
            $supportAgent = $session->supportAgent;
            if ($supportAgent) {
                $this->notifyUser(
                    $supportAgent,
                    $session,
                    $message->content,
                    $connectedUserIds
                );
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
            'connected_users' => $connectedUserIds->count(),
        ]);

        foreach ($supportUsers as $user) {
            $this->notifyUser($user, $session, $message->content, $connectedUserIds);
        }
    }

    /**
     * Notifie un utilisateur via Filament + email si non connecté.
     */
    protected function notifyUser(
        User $user,
        $session,
        string $messageContent,
        Collection $connectedUserIds
    ): void {
        // Toujours envoyer la notification Filament (visible à la reconnexion)
        $user->notify(new NewSupportMessageNotification(
            session: $session,
            senderType: 'user',
            messagePreview: $messageContent
        ));

        // Si l'utilisateur n'est pas connecté, envoyer aussi un email
        $isConnected = $connectedUserIds->contains($user->id);

        if (!$isConnected && $user->email) {
            Log::info('NewSupportMessage: User offline, sending email', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'session_id' => $session->id,
            ]);

            $senderName = $session->user?->name ?? $session->metadata['visitor_name'] ?? 'Visiteur';

            Mail::to($user->email)->queue(new NewMessageNotificationMail(
                session: $session,
                messagePreview: $messageContent,
                senderName: $senderName,
            ));
        }
    }

    /**
     * Récupère les IDs des utilisateurs connectés au canal de présence de l'agent.
     */
    protected function getConnectedUserIds(int $agentId): Collection
    {
        try {
            $connectedUsers = $this->presenceService->getConnectedAgents($agentId);

            return collect($connectedUsers)->pluck('id')->filter();
        } catch (\Throwable $e) {
            Log::warning('Failed to get connected users', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            // En cas d'erreur, considérer personne comme connecté (envoyer les emails)
            return collect();
        }
    }
}
