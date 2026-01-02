<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\AiSession;
use App\Models\SupportMessage;
use App\Models\User;
use App\Events\Support\NewSupportMessage;
use App\Services\AI\DispatcherService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class SupportService
{
    public function __construct(
        protected EscalationService $escalationService,
        protected DispatcherService $dispatcherService,
        protected PresenceService $presenceService
    ) {}

    /**
     * Envoie un message de l'agent de support vers l'utilisateur.
     *
     * Si l'utilisateur a fourni un email (mode async), le message est aussi
     * envoyé par email en plus du temps réel.
     */
    public function sendAgentMessage(
        AiSession $session,
        User $agent,
        string $content,
        string $channel = 'chat',
        ?string $originalContent = null,
        bool $sendEmailIfAvailable = true
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

        // Envoyer par email si l'utilisateur a fourni son email ET n'est pas connecté au chat
        $hasEmail = !empty($session->user_email);

        // Vérifier la présence via Soketi (canal de présence WebSocket)
        $isUserOnline = $this->presenceService->isSessionUserOnline($session->uuid);

        $shouldSendEmail = $sendEmailIfAvailable && $hasEmail && $channel === 'chat' && !$isUserOnline;

        Log::debug('SupportService: Email notification check', [
            'session_id' => $session->id,
            'session_uuid' => $session->uuid,
            'message_id' => $message->id,
            'send_email_if_available' => $sendEmailIfAvailable,
            'user_email' => $session->user_email,
            'channel' => $channel,
            'is_user_online' => $isUserOnline,
            'should_send_email' => $shouldSendEmail,
        ]);

        if ($shouldSendEmail) {
            $this->sendEmailNotificationForMessage($session, $message, $agent);
        } elseif ($hasEmail && $isUserOnline) {
            Log::debug('SupportService: User online via Soketi, skipping email', [
                'session_id' => $session->id,
                'message_id' => $message->id,
            ]);
        }

        return $message;
    }

    /**
     * Envoie une notification email pour un message de support.
     */
    protected function sendEmailNotificationForMessage(
        AiSession $session,
        SupportMessage $message,
        User $agent
    ): void {
        // Générer un token d'accès si pas encore fait
        if (!$session->support_access_token) {
            $session->generateSupportAccessToken();
            $session->refresh();
        }

        Log::info('SupportService: Sending email notification to user', [
            'session_id' => $session->id,
            'message_id' => $message->id,
            'user_email' => $session->user_email,
        ]);

        try {
            $mailable = new \App\Mail\Support\SupportReplyMail($session, $message, $agent);
            $smtpConfig = $session->agent?->getSmtpConfig();

            if ($smtpConfig) {
                $this->sendWithCustomSmtp($session->user_email, $mailable, $smtpConfig);
                Log::info('SupportService: Email sent via custom SMTP', [
                    'session_id' => $session->id,
                    'message_id' => $message->id,
                    'to' => $session->user_email,
                ]);
            } else {
                // Envoi synchrone pour garantir la livraison immédiate
                \Illuminate\Support\Facades\Mail::to($session->user_email)->send($mailable);
                Log::info('SupportService: Email sent via default mailer', [
                    'session_id' => $session->id,
                    'message_id' => $message->id,
                    'to' => $session->user_email,
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('SupportService: Failed to send email notification', [
                'session_id' => $session->id,
                'message_id' => $message->id,
                'user_email' => $session->user_email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Enregistre un message de l'utilisateur.
     */
    public function receiveUserMessage(
        AiSession $session,
        string $content,
        string $channel = 'chat',
        ?array $emailMetadata = null,
        bool $triggerAiProcessing = true
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

        // Dispatcher l'événement pour notifications temps réel
        event(new NewSupportMessage($message));

        // Déclencher le traitement IA si demandé
        if ($triggerAiProcessing && $session->agent) {
            $this->triggerAiProcessing($session, $content);
        }

        return $message;
    }

    /**
     * Déclenche le traitement IA pour un message utilisateur.
     * L'IA génère une réponse qui sera visible par l'agent support.
     */
    protected function triggerAiProcessing(AiSession $session, string $userMessage): void
    {
        try {
            Log::info('Triggering AI processing for support message', [
                'session_id' => $session->id,
                'agent_id' => $session->agent_id,
            ]);

            // Dispatcher de manière asynchrone pour ne pas bloquer
            $this->dispatcherService->dispatchAsync(
                $userMessage,
                $session->agent,
                $session->user,
                $session,
                'support-email'
            );
        } catch (\Throwable $e) {
            Log::error('Failed to trigger AI processing for support message', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            // Ne pas lever l'exception - le message support a déjà été créé
        }
    }

    /**
     * Enregistre un message système.
     */
    public function addSystemMessage(
        AiSession $session,
        string $content
    ): SupportMessage {
        $message = SupportMessage::create([
            'session_id' => $session->id,
            'sender_type' => 'system',
            'channel' => 'chat',
            'content' => $content,
            'is_read' => true,
        ]);

        // Dispatcher l'événement pour le temps réel
        event(new NewSupportMessage($message));

        return $message;
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
     *
     * Supporte SSL (port 465) et TLS (port 587).
     */
    protected function sendWithCustomSmtp(string $to, $mailable, array $smtpConfig): void
    {
        $encryption = strtolower($smtpConfig['encryption'] ?? 'tls');
        $port = (int) $smtpConfig['port'];

        // Construire le DSN Symfony Mailer
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

        Log::debug('Creating SMTP transport', [
            'scheme' => $scheme,
            'host' => $smtpConfig['host'],
            'port' => $port,
            'encryption' => $encryption,
        ]);

        // Créer le transport via DSN
        $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);

        // Configurer le from sur le mailable
        $mailable->from($smtpConfig['from_address'], $smtpConfig['from_name']);

        // Extraire le sujet de l'Envelope avant le render
        $subject = 'Support';
        if (method_exists($mailable, 'envelope')) {
            $envelope = $mailable->envelope();
            $subject = $envelope->subject ?? $subject;
        }

        // Rendre le mailable et envoyer
        $symfonyMessage = $mailable->to($to)->render();

        Log::debug('Sending email via custom SMTP', [
            'to' => $to,
            'subject' => $subject,
            'from' => $smtpConfig['from_address'],
        ]);

        // Créer un email Symfony à partir du mailable Laravel
        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address($smtpConfig['from_address'], $smtpConfig['from_name']))
            ->to($to)
            ->subject($subject)
            ->html($symfonyMessage);

        $mailer->send($email);

        Log::info('Email sent via custom SMTP', [
            'to' => $to,
            'subject' => $subject,
        ]);
    }

    /**
     * Envoie un message IA validé par email au client.
     *
     * Cette méthode est utilisée quand on valide un message IA : on envoie juste l'email
     * sans créer de SupportMessage car le message IA existe déjà.
     * L'email est toujours envoyé si l'utilisateur a un email (le message WebSocket est aussi envoyé).
     */
    public function sendValidatedAiMessageByEmail(
        AiSession $session,
        \App\Models\AiMessage $aiMessage,
        \App\Models\User $validator
    ): bool {
        if (!$session->user_email) {
            Log::warning('Cannot send validated AI message: no user email on session', [
                'session_id' => $session->id,
            ]);
            return false;
        }

        // Générer un token d'accès si pas encore fait
        if (!$session->support_access_token) {
            $session->generateSupportAccessToken();
            $session->refresh();
        }

        try {
            // Utiliser un Mailable spécifique pour les réponses IA validées
            $mailable = new \App\Mail\Support\ValidatedAiResponseMail($session, $aiMessage);
            $smtpConfig = $session->agent?->getSmtpConfig();

            if ($smtpConfig) {
                $this->sendWithCustomSmtp($session->user_email, $mailable, $smtpConfig);
            } else {
                \Illuminate\Support\Facades\Mail::to($session->user_email)->send($mailable);
            }

            Log::info('Validated AI message sent by email', [
                'session_id' => $session->id,
                'ai_message_id' => $aiMessage->id,
                'to' => $session->user_email,
                'custom_smtp' => $smtpConfig !== null,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('Failed to send validated AI message by email', [
                'session_id' => $session->id,
                'ai_message_id' => $aiMessage->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Envoie un email de confirmation au client après qu'il ait fourni son email.
     */
    public function sendEmailConfirmationToUser(AiSession $session): bool
    {
        if (!$session->user_email) {
            Log::warning('Cannot send confirmation email: no user email on session', [
                'session_id' => $session->id,
            ]);
            return false;
        }

        $agent = $session->agent;
        if (!$agent) {
            Log::warning('Cannot send confirmation email: no agent on session', [
                'session_id' => $session->id,
            ]);
            return false;
        }

        try {
            $mailable = new \App\Mail\Support\UserEmailConfirmationMail($session);
            $smtpConfig = $agent->getSmtpConfig();

            if ($smtpConfig) {
                $this->sendWithCustomSmtp($session->user_email, $mailable, $smtpConfig);
                Log::info('Confirmation email sent to user via custom SMTP', [
                    'session_id' => $session->id,
                    'to' => $session->user_email,
                    'smtp_host' => $smtpConfig['host'],
                ]);
            } else {
                // Utiliser la config par défaut de Laravel
                \Illuminate\Support\Facades\Mail::to($session->user_email)->send($mailable);
                Log::info('Confirmation email sent to user via default mailer', [
                    'session_id' => $session->id,
                    'to' => $session->user_email,
                ]);
            }

            return true;

        } catch (\Throwable $e) {
            Log::error('Failed to send confirmation email to user', [
                'session_id' => $session->id,
                'email' => $session->user_email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}
