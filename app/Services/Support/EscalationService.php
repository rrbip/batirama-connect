<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Events\Support\SessionAssigned;
use App\Events\Support\SessionEscalated;
use App\Mail\Support\EscalationNotificationMail;
use App\Models\Agent;
use App\Models\AiSession;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EscalationService
{
    /**
     * Vérifie si une session doit être escaladée en fonction du score RAG.
     */
    public function shouldEscalate(AiSession $session, float $maxRagScore): bool
    {
        $agent = $session->agent;

        if (!$agent || !$agent->human_support_enabled) {
            return false;
        }

        $threshold = $agent->escalation_threshold ?? 0.60;

        return $maxRagScore < $threshold;
    }

    /**
     * Escalade une session vers le support humain.
     */
    public function escalate(
        AiSession $session,
        string $reason,
        ?float $maxRagScore = null,
        ?string $userEmail = null
    ): AiSession {
        // Mettre à jour la session
        $session->update([
            'support_status' => 'escalated',
            'escalation_reason' => $reason,
            'escalated_at' => now(),
            'user_email' => $userEmail ?? $session->user_email,
            'support_metadata' => array_merge($session->support_metadata ?? [], [
                'max_rag_score' => $maxRagScore,
                'escalated_by' => 'system',
                'escalated_at_utc' => now()->utc()->toISOString(),
            ]),
        ]);

        Log::info('Session escalated to human support', [
            'session_id' => $session->id,
            'session_uuid' => $session->uuid,
            'agent_id' => $session->agent_id,
            'reason' => $reason,
            'max_rag_score' => $maxRagScore,
        ]);

        // Créer le message système d'escalade pour l'historique
        $this->createEscalationSystemMessage($session);

        // Dispatcher l'événement (pour WebSocket)
        event(new SessionEscalated($session));

        // Vérifier si des agents de support sont disponibles
        $this->notifyAvailableAgents($session);

        return $session;
    }

    /**
     * Crée un message système d'escalade dans l'historique.
     */
    protected function createEscalationSystemMessage(AiSession $session): void
    {
        $agent = $session->agent;
        $asyncMode = $agent?->shouldUseAsyncSupport() ?? true;

        $content = $asyncMode
            ? 'Votre demande a été transmise à notre équipe. Un conseiller vous répondra dès que possible.'
            : 'Votre demande a été transmise à notre équipe. Un conseiller va vous répondre.';

        $message = $session->supportMessages()->create([
            'sender_type' => 'system',
            'channel' => 'chat',
            'content' => $content,
        ]);

        // Broadcast le message pour les clients connectés
        event(new \App\Events\Support\NewSupportMessage($message));
    }

    /**
     * Assigne une session à un agent de support.
     */
    public function assignToAgent(AiSession $session, User $agent): AiSession
    {
        $session->update([
            'support_status' => 'assigned',
            'support_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        Log::info('Session assigned to support agent', [
            'session_id' => $session->id,
            'agent_user_id' => $agent->id,
            'agent_name' => $agent->name,
        ]);

        // Dispatcher l'événement
        event(new SessionAssigned($session, $agent));

        return $session;
    }

    /**
     * Trouve les agents de support disponibles pour une session.
     * Pas de fallback vers admins - système marque blanche.
     */
    public function findAvailableAgents(AiSession $session): Collection
    {
        $agent = $session->agent;

        if (!$agent) {
            return collect();
        }

        // Agents assignés spécifiquement à cet agent IA avec notify_on_escalation
        $assignedAgents = $agent->supportUsers()
            ->wherePivot('notify_on_escalation', true)
            ->get();

        if ($assignedAgents->isNotEmpty()) {
            return $assignedAgents;
        }

        // Sinon tous les agents de support assignés
        return $agent->supportUsers;
    }

    /**
     * Récupère les IDs des utilisateurs connectés au canal de présence.
     */
    protected function getConnectedUserIds(AiSession $session): Collection
    {
        if (!$session->agent_id) {
            return collect();
        }

        try {
            $presenceService = app(PresenceService::class);
            $connectedUsers = $presenceService->getConnectedAgents($session->agent_id);

            Log::debug('EscalationService: Raw connected users from Soketi', [
                'agent_id' => $session->agent_id,
                'raw_data' => $connectedUsers,
            ]);

            return collect($connectedUsers)->pluck('id')->filter();
        } catch (\Throwable $e) {
            Log::warning('EscalationService: Failed to get connected users', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            // En cas d'erreur, considérer personne comme connecté (envoyer les emails)
            return collect();
        }
    }

    /**
     * Notifie les agents de support disponibles.
     * Pour chaque agent : notification cloche + email si non connecté.
     */
    public function notifyAvailableAgents(AiSession $session): void
    {
        $agents = $this->findAvailableAgents($session);

        if ($agents->isEmpty()) {
            Log::warning('No support agents available for escalated session', [
                'session_id' => $session->id,
                'agent_id' => $session->agent_id,
            ]);
            return;
        }

        // Récupérer les IDs des utilisateurs connectés
        $connectedUserIds = $this->getConnectedUserIds($session);

        Log::info('EscalationService: Notifying agents', [
            'session_id' => $session->id,
            'agents_count' => $agents->count(),
            'agents_ids' => $agents->pluck('id')->toArray(),
            'connected_user_ids' => $connectedUserIds->toArray(),
        ]);

        $emailsSent = 0;

        foreach ($agents as $agent) {
            // Toujours envoyer la notification Filament (cloche)
            $this->sendDatabaseNotificationToUser($session, $agent);

            // Si l'agent n'est pas connecté, envoyer aussi un email
            $isConnected = $connectedUserIds->contains($agent->id);

            Log::debug('EscalationService: Agent notification check', [
                'agent_id' => $agent->id,
                'agent_email' => $agent->email,
                'is_connected' => $isConnected,
                'will_send_email' => !$isConnected && $agent->email,
            ]);

            if (!$isConnected && $agent->email) {
                $this->sendEmailNotificationToUser($session, $agent);
                $emailsSent++;
            }
        }

        // Mettre à jour les métadonnées si des emails ont été envoyés
        if ($emailsSent > 0) {
            $session->update([
                'support_metadata' => array_merge($session->support_metadata ?? [], [
                    'email_notification_sent_at' => now()->toISOString(),
                    'notified_agents_count' => $agents->count(),
                    'emails_sent_count' => $emailsSent,
                ]),
            ]);
        }
    }

    /**
     * Envoie une notification Filament (cloche) à un utilisateur.
     */
    protected function sendDatabaseNotificationToUser(AiSession $session, User $user): void
    {
        $reasonLabels = [
            'ai_handoff_request' => "L'IA a demandé un transfert",
            'user_explicit_request' => "L'utilisateur demande un humain",
            'low_confidence' => 'Score de confiance trop bas',
            'low_rag_score' => 'Score de confiance trop bas',
            'manual_request' => 'Demande de support humain',
        ];

        $reasonLabel = $reasonLabels[$session->escalation_reason] ?? $session->escalation_reason;
        $agentName = $session->agent?->name ?? 'Agent IA';
        $userName = $session->user_email ?? $session->user?->name ?? 'Visiteur';

        try {
            Notification::make()
                ->title('Nouvelle escalade support')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('danger')
                ->body("{$reasonLabel}\n**{$agentName}** - De : {$userName}")
                ->actions([
                    Action::make('view')
                        ->label('Voir la session')
                        ->url("/admin/ai-sessions/{$session->id}")
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);

            Log::debug('Database notification sent to support agent', [
                'session_id' => $session->id,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send database notification', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoie un email de notification d'escalade à un utilisateur.
     */
    protected function sendEmailNotificationToUser(AiSession $session, User $user): void
    {
        try {
            $mailable = new EscalationNotificationMail($session, $user);

            // Utiliser le SMTP personnalisé de l'agent IA si disponible
            $smtpConfig = $session->agent?->getSmtpConfig();

            if ($smtpConfig) {
                $this->sendWithCustomSmtp($user->email, $mailable, $smtpConfig);
            } else {
                Mail::to($user->email)->send($mailable);
            }

            Log::info('Escalation email sent to support agent', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'custom_smtp' => (bool) $smtpConfig,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send escalation email', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoie un email via un SMTP personnalisé.
     */
    protected function sendWithCustomSmtp(string $to, $mailable, array $smtpConfig): void
    {
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $smtpConfig['host'],
            $smtpConfig['port'],
            $smtpConfig['encryption'] === 'tls'
        );
        $transport->setUsername($smtpConfig['username']);
        $transport->setPassword($smtpConfig['password']);

        $mailer = new \Symfony\Component\Mailer\Mailer($transport);
        $symfonyMailer = new \Illuminate\Mail\Mailer(
            'custom',
            app('view'),
            $mailer,
            app('events')
        );

        $symfonyMailer->to($to)->send($mailable);
    }

    /**
     * Retourne le message d'escalade configuré pour l'agent.
     */
    public function getEscalationMessage(Agent $agent): string
    {
        return $agent->escalation_message
            ?? "Je n'ai pas trouvé d'information fiable pour répondre à votre question. Un conseiller va prendre le relais.";
    }

    /**
     * Retourne le message quand aucun agent n'est disponible.
     */
    public function getNoAgentMessage(Agent $agent): string
    {
        return $agent->no_admin_message
            ?? "Aucun conseiller n'est disponible pour le moment. Laissez-nous votre email et nous vous répondrons dès que possible.";
    }

    /**
     * Marque une session comme résolue.
     */
    public function resolve(
        AiSession $session,
        string $resolutionType,
        ?string $notes = null
    ): AiSession {
        $session->update([
            'support_status' => 'resolved',
            'resolved_at' => now(),
            'resolution_type' => $resolutionType,
            'resolution_notes' => $notes,
        ]);

        Log::info('Support session resolved', [
            'session_id' => $session->id,
            'resolution_type' => $resolutionType,
            'resolved_by' => auth()->id(),
        ]);

        return $session;
    }

    /**
     * Marque une session comme abandonnée.
     */
    public function markAsAbandoned(AiSession $session): AiSession
    {
        $session->update([
            'support_status' => 'abandoned',
            'support_metadata' => array_merge($session->support_metadata ?? [], [
                'abandoned_at' => now()->toISOString(),
            ]),
        ]);

        return $session;
    }
}
