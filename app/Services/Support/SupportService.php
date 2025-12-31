<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\AiSession;
use App\Models\SupportMessage;
use App\Models\User;
use App\Events\Support\NewSupportMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class SupportService
{
    public function __construct(
        protected EscalationService $escalationService
    ) {}

    /**
     * Envoie un message de l'agent de support vers l'utilisateur.
     */
    public function sendAgentMessage(
        AiSession $session,
        User $agent,
        string $content,
        string $channel = 'chat',
        ?string $originalContent = null
    ): SupportMessage {
        $message = SupportMessage::create([
            'session_id' => $session->id,
            'sender_type' => 'agent',
            'agent_id' => $agent->id,
            'channel' => $channel,
            'content' => $content,
            'original_content' => $originalContent,
            'was_ai_improved' => $originalContent !== null && $originalContent !== $content,
        ]);

        Log::info('Support agent sent message', [
            'session_id' => $session->id,
            'message_id' => $message->id,
            'agent_id' => $agent->id,
            'channel' => $channel,
        ]);

        // Mettre à jour l'activité de la session
        $session->touch('last_activity_at');

        // Dispatcher l'événement pour le temps réel
        event(new NewSupportMessage($message));

        return $message;
    }

    /**
     * Enregistre un message de l'utilisateur.
     */
    public function receiveUserMessage(
        AiSession $session,
        string $content,
        string $channel = 'chat',
        ?array $emailMetadata = null
    ): SupportMessage {
        $message = SupportMessage::create([
            'session_id' => $session->id,
            'sender_type' => 'user',
            'channel' => $channel,
            'content' => $content,
            'email_metadata' => $emailMetadata,
            'is_read' => false,
        ]);

        Log::info('User sent support message', [
            'session_id' => $session->id,
            'message_id' => $message->id,
            'channel' => $channel,
        ]);

        // Mettre à jour l'activité
        $session->touch('last_activity_at');
        $session->update([
            'support_metadata' => array_merge($session->support_metadata ?? [], [
                'last_user_activity' => now()->toISOString(),
                'user_online' => true,
            ]),
        ]);

        // Dispatcher l'événement
        event(new NewSupportMessage($message));

        return $message;
    }

    /**
     * Enregistre un message système.
     */
    public function addSystemMessage(
        AiSession $session,
        string $content
    ): SupportMessage {
        return SupportMessage::create([
            'session_id' => $session->id,
            'sender_type' => 'system',
            'channel' => 'chat',
            'content' => $content,
            'is_read' => true,
        ]);
    }

    /**
     * Récupère tous les messages de support d'une session.
     */
    public function getMessages(AiSession $session): Collection
    {
        return $session->supportMessages()
            ->with('agent', 'attachments')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Récupère les nouveaux messages depuis un certain timestamp.
     */
    public function getNewMessages(AiSession $session, string $since): Collection
    {
        return $session->supportMessages()
            ->where('created_at', '>', $since)
            ->with('agent', 'attachments')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Marque tous les messages d'une session comme lus.
     */
    public function markAllAsRead(AiSession $session): int
    {
        return $session->supportMessages()
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /**
     * Récupère les sessions en attente de support pour un agent IA.
     */
    public function getPendingSessions(int $agentId): Collection
    {
        return AiSession::where('agent_id', $agentId)
            ->where('support_status', 'escalated')
            ->orderBy('escalated_at', 'asc')
            ->with(['user', 'agent', 'supportMessages'])
            ->get();
    }

    /**
     * Récupère les sessions assignées à un agent de support.
     */
    public function getAssignedSessions(User $supportAgent): Collection
    {
        return AiSession::where('support_agent_id', $supportAgent->id)
            ->where('support_status', 'assigned')
            ->orderBy('assigned_at', 'desc')
            ->with(['user', 'agent', 'supportMessages'])
            ->get();
    }

    /**
     * Récupère toutes les sessions de support actives (escaladées ou assignées).
     */
    public function getActiveSupportSessions(?int $agentId = null): Collection
    {
        $query = AiSession::whereIn('support_status', ['escalated', 'assigned'])
            ->orderByRaw("CASE WHEN support_status = 'escalated' THEN 0 ELSE 1 END")
            ->orderBy('escalated_at', 'asc')
            ->with(['user', 'agent', 'supportAgent', 'supportMessages']);

        if ($agentId) {
            $query->where('agent_id', $agentId);
        }

        return $query->get();
    }

    /**
     * Calcule le nombre de messages non lus pour une session.
     */
    public function getUnreadCount(AiSession $session): int
    {
        return $session->supportMessages()
            ->where('sender_type', 'user')
            ->where('is_read', false)
            ->count();
    }

    /**
     * Prend en charge une session (l'assigne à l'agent courant).
     */
    public function takeOverSession(AiSession $session, User $agent): AiSession
    {
        $this->escalationService->assignToAgent($session, $agent);

        // Ajouter un message système
        $this->addSystemMessage(
            $session,
            "{$agent->name} a pris en charge votre demande."
        );

        return $session->fresh();
    }

    /**
     * Résout une session avec une réponse finale.
     */
    public function resolveSession(
        AiSession $session,
        User $agent,
        string $resolutionType,
        ?string $notes = null
    ): AiSession {
        $this->escalationService->resolve($session, $resolutionType, $notes);

        // Ajouter un message système
        $resolutionLabel = match ($resolutionType) {
            'answered' => 'Votre demande a été traitée.',
            'redirected' => 'Vous avez été redirigé vers le service approprié.',
            'out_of_scope' => 'Cette demande est hors de notre périmètre.',
            'duplicate' => 'Cette demande a déjà été traitée.',
            default => 'La conversation est terminée.',
        };

        $this->addSystemMessage($session, $resolutionLabel);

        return $session->fresh();
    }

    /**
     * Transfert une session à un autre agent.
     */
    public function transferSession(
        AiSession $session,
        User $fromAgent,
        User $toAgent
    ): AiSession {
        $session->update([
            'support_agent_id' => $toAgent->id,
            'assigned_at' => now(),
        ]);

        $this->addSystemMessage(
            $session,
            "La conversation a été transférée de {$fromAgent->name} à {$toAgent->name}."
        );

        Log::info('Support session transferred', [
            'session_id' => $session->id,
            'from_agent' => $fromAgent->id,
            'to_agent' => $toAgent->id,
        ]);

        return $session->fresh();
    }

    /**
     * Envoie un message par email à l'utilisateur.
     */
    public function sendEmailResponse(
        AiSession $session,
        User $agent,
        string $content,
        ?string $originalContent = null
    ): ?SupportMessage {
        // Vérifier que l'utilisateur a un email
        if (!$session->user_email) {
            Log::warning('Cannot send email: no user email on session', [
                'session_id' => $session->id,
            ]);
            return null;
        }

        // Générer un token d'accès si pas encore fait
        if (!$session->support_access_token) {
            $session->generateSupportAccessToken();
            $session->refresh();
        }

        // Créer le message de support
        $message = $this->sendAgentMessage(
            $session,
            $agent,
            $content,
            'email',
            $originalContent
        );

        // Envoyer l'email avec la config SMTP de l'agent si disponible
        try {
            $mailable = new \App\Mail\Support\SupportReplyMail($session, $message, $agent);

            // Utiliser la config SMTP de l'agent IA si disponible
            $smtpConfig = $session->agent?->getSmtpConfig();

            if ($smtpConfig) {
                $this->sendWithCustomSmtp($session->user_email, $mailable, $smtpConfig);
            } else {
                // Utiliser la config par défaut de Laravel
                \Illuminate\Support\Facades\Mail::to($session->user_email)->queue($mailable);
            }

            Log::info('Support email sent', [
                'session_id' => $session->id,
                'message_id' => $message->id,
                'to' => $session->user_email,
                'custom_smtp' => $smtpConfig !== null,
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to send support email', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $message;
    }

    /**
     * Envoie un email avec une configuration SMTP personnalisée.
     */
    protected function sendWithCustomSmtp(string $to, $mailable, array $smtpConfig): void
    {
        // Créer un transport SMTP personnalisé
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $smtpConfig['host'],
            $smtpConfig['port'],
            $smtpConfig['encryption'] === 'tls'
        );

        $transport->setUsername($smtpConfig['username']);
        $transport->setPassword($smtpConfig['password']);

        // Créer un mailer avec ce transport
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);

        // Configurer le from sur le mailable
        $mailable->from($smtpConfig['from_address'], $smtpConfig['from_name']);

        // Rendre le mailable et envoyer
        $symfonyMessage = $mailable->to($to)->render();

        // Créer un email Symfony à partir du mailable Laravel
        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address($smtpConfig['from_address'], $smtpConfig['from_name']))
            ->to($to)
            ->subject($mailable->subject ?? 'Support')
            ->html($symfonyMessage);

        $mailer->send($email);
    }
}
