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

        // Dispatcher l'événement (pour WebSocket)
        event(new SessionEscalated($session));

        // Vérifier si des agents de support sont disponibles
        $this->notifyAvailableAgents($session);

        return $session;
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
     */
    public function findAvailableAgents(AiSession $session): Collection
    {
        $agent = $session->agent;

        if (!$agent) {
            return collect();
        }

        // 1. Agents assignés spécifiquement à cet agent IA
        $assignedAgents = $agent->supportUsers()
            ->wherePivot('notify_on_escalation', true)
            ->get();

        if ($assignedAgents->isNotEmpty()) {
            return $assignedAgents;
        }

        // 2. Fallback : Admins et super-admins
        return User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['super-admin', 'admin']);
        })->get();
    }

    /**
     * Vérifie si des agents de support sont connectés via Soketi.
     */
    public function hasConnectedAgents(AiSession $session): bool
    {
        if (!$session->agent_id) {
            return false;
        }

        $presenceService = app(PresenceService::class);
        return $presenceService->hasConnectedAgents($session->agent_id);
    }

    /**
     * Notifie les agents de support disponibles.
     */
    public function notifyAvailableAgents(AiSession $session): void
    {
        $agents = $this->findAvailableAgents($session);

        if ($agents->isEmpty()) {
            Log::warning('No support agents available for escalated session', [
                'session_id' => $session->id,
            ]);
            return;
        }

        // Envoyer des notifications database Filament (cloche)
        $this->sendDatabaseNotifications($session, $agents);

        // Si aucun agent n'est connecté, envoyer un email
        if (!$this->hasConnectedAgents($session)) {
            $this->sendEmailNotifications($session, $agents);
        }
    }

    /**
     * Envoie des notifications database Filament aux agents de support.
     * Ces notifications apparaissent dans la cloche de l'interface admin.
     */
    protected function sendDatabaseNotifications(AiSession $session, Collection $agents): void
    {
        $reasonLabels = [
            'ai_handoff_request' => "L'IA a demandé un transfert",
            'user_explicit_request' => "L'utilisateur demande un humain",
            'low_confidence' => 'Score de confiance trop bas',
            'manual_request' => 'Demande de support humain',
        ];

        $reasonLabel = $reasonLabels[$session->escalation_reason] ?? $session->escalation_reason;
        $agentName = $session->agent?->name ?? 'Agent IA';
        $userName = $session->user_email ?? $session->user?->name ?? 'Visiteur';

        foreach ($agents as $agent) {
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
                    ->sendToDatabase($agent);

                Log::debug('Database notification sent to support agent', [
                    'session_id' => $session->id,
                    'agent_id' => $agent->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send database notification', [
                    'session_id' => $session->id,
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Envoie des notifications par email aux agents de support.
     *
     * Note: On vérifie si une notification par email a déjà été envoyée récemment
     * pour éviter les doublons (ex: NewMessageNotificationMail + EscalationNotificationMail).
     */
    protected function sendEmailNotifications(AiSession $session, Collection $agents): void
    {
        // Vérifier si une notification email a été envoyée dans les 60 dernières secondes
        // pour éviter d'envoyer un email d'escalade juste après un email de nouveau message
        $lastNotificationAt = $session->support_metadata['email_notification_sent_at'] ?? null;
        if ($lastNotificationAt) {
            $lastNotification = \Carbon\Carbon::parse($lastNotificationAt);
            if ($lastNotification->diffInSeconds(now()) < 60) {
                Log::info('Skipping escalation email - recent notification already sent', [
                    'session_id' => $session->id,
                    'last_notification_at' => $lastNotificationAt,
                    'seconds_ago' => $lastNotification->diffInSeconds(now()),
                ]);
                return;
            }
        }

        foreach ($agents as $agent) {
            if (!$agent->email) {
                continue;
            }

            try {
                Mail::to($agent->email)->queue(new EscalationNotificationMail($session, $agent));

                Log::info('Escalation email sent to support agent', [
                    'session_id' => $session->id,
                    'agent_email' => $agent->email,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send escalation email', [
                    'session_id' => $session->id,
                    'agent_email' => $agent->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Mettre à jour les métadonnées
        $session->update([
            'support_metadata' => array_merge($session->support_metadata ?? [], [
                'notification_sent_at' => now()->toISOString(),
                'email_notification_sent_at' => now()->toISOString(),
                'notified_agents_count' => $agents->count(),
            ]),
        ]);
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
