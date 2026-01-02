<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\Events\Support\NewSupportMessage;
use App\Mail\Support\NewMessageNotificationMail;
use App\Models\User;
use App\Services\Support\PresenceService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

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
     * The queue this job should run on.
     */
    public string $queue = 'default';

    /**
     * Handle the event.
     */
    public function handle(NewSupportMessage $event): void
    {
        $message = $event->message;
        $session = $message->session;

        // Ne notifier que pour les messages utilisateurs (pas les messages agents ou système)
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

        // Fallback: si toujours vide, notifier les admins/super-admins
        if ($supportUsers->isEmpty()) {
            $supportUsers = User::whereHas('roles', function ($query) {
                $query->whereIn('name', ['super-admin', 'admin']);
            })->get();

            Log::info('NewSupportMessage: Using admin fallback', [
                'session_id' => $session->id,
                'admin_count' => $supportUsers->count(),
            ]);
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
        $agentName = $session->agent?->name ?? 'Agent inconnu';
        $userName = $session->user?->name ?? $session->user_email ?? 'Visiteur';
        $preview = Str::limit($messageContent, 80);

        // Toujours envoyer la notification Filament (visible à la reconnexion)
        try {
            Notification::make()
                ->title("Message de {$userName}")
                ->icon('heroicon-o-chat-bubble-bottom-center-text')
                ->iconColor('info')
                ->body("{$agentName} - {$preview}")
                ->actions([
                    Action::make('view')
                        ->label('Répondre')
                        ->url("/admin/ai-sessions/{$session->id}")
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);

            Log::debug('NewSupportMessage: Filament notification sent', [
                'user_id' => $user->id,
                'session_id' => $session->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('NewSupportMessage: Failed to send Filament notification', [
                'user_id' => $user->id,
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Si l'utilisateur n'est pas connecté, envoyer aussi un email
        $isConnected = $connectedUserIds->contains($user->id);

        if (!$isConnected && $user->email) {
            Log::info('NewSupportMessage: User offline, sending email', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'session_id' => $session->id,
            ]);

            $senderName = $session->user?->name ?? $session->metadata['visitor_name'] ?? 'Visiteur';

            // Utiliser le SMTP personnalisé de l'agent IA si disponible
            $smtpConfig = $session->agent?->getSmtpConfig();

            $mailable = new NewMessageNotificationMail(
                session: $session,
                messagePreview: $messageContent,
                senderName: $senderName,
            );

            try {
                if ($smtpConfig) {
                    $this->sendWithCustomSmtp($user->email, $mailable, $smtpConfig);
                    Log::info('NewSupportMessage: Email sent via custom SMTP', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'session_id' => $session->id,
                    ]);
                } else {
                    // Fallback au mailer par défaut
                    Mail::to($user->email)->send($mailable);
                    Log::info('NewSupportMessage: Email sent via default mailer', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'session_id' => $session->id,
                    ]);
                }

                // Marquer qu'un email a été envoyé pour éviter les doublons avec l'email d'escalade
                $session->update([
                    'support_metadata' => array_merge($session->support_metadata ?? [], [
                        'email_notification_sent_at' => now()->toISOString(),
                    ]),
                ]);
            } catch (\Throwable $e) {
                Log::error('NewSupportMessage: Failed to send email', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Envoie un email avec une configuration SMTP personnalisée.
     */
    protected function sendWithCustomSmtp(string $to, $mailable, array $smtpConfig): void
    {
        $encryption = strtolower($smtpConfig['encryption'] ?? 'tls');
        $port = (int) $smtpConfig['port'];

        // SSL (port 465): smtps://user:pass@host:465
        // TLS (port 587): smtp://user:pass@host:587
        $scheme = ($encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp';

        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            urlencode($smtpConfig['username']),
            urlencode($smtpConfig['password']),
            $smtpConfig['host'],
            $port
        );

        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        // Extraire le sujet de l'Envelope
        $subject = 'Nouveau message support';
        if (method_exists($mailable, 'envelope')) {
            $envelope = $mailable->envelope();
            $subject = $envelope->subject ?? $subject;
        }

        // Rendre le contenu HTML
        $mailable->from($smtpConfig['from_address'], $smtpConfig['from_name']);
        $htmlContent = $mailable->to($to)->render();

        // Créer et envoyer l'email Symfony
        $email = (new Email())
            ->from(new Address($smtpConfig['from_address'], $smtpConfig['from_name']))
            ->to($to)
            ->subject($subject)
            ->html($htmlContent);

        $mailer->send($email);
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
