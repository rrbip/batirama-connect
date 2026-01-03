<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Models\AiSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notification envoyée aux agents de support quand une session est escaladée.
 */
class SessionEscalatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiSession $session
    ) {}

    /**
     * Canaux de notification.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Données pour la notification Filament (database).
     */
    public function toDatabase(object $notifiable): array
    {
        $agentName = $this->session->agent?->name ?? 'Agent inconnu';
        $userName = $this->session->user?->name ?? 'Visiteur';
        $userEmail = $this->session->user_email;

        $reasonLabel = match ($this->session->escalation_reason) {
            'low_confidence' => 'Score RAG insuffisant',
            'user_request' => 'Demande utilisateur',
            'ai_uncertainty' => 'Incertitude de l\'IA',
            'negative_feedback' => 'Feedback négatif',
            default => $this->session->escalation_reason ?? 'Non spécifié',
        };

        return [
            'title' => "Nouvelle demande de support - {$agentName}",
            'body' => $userEmail
                ? "De {$userName} ({$userEmail}) - Raison: {$reasonLabel}"
                : "De {$userName} - Raison: {$reasonLabel}",
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'iconColor' => 'danger',
            'actions' => [
                [
                    'name' => 'view',
                    'label' => 'Voir la conversation',
                    'url' => route('filament.admin.resources.ai-sessions.view', ['record' => $this->session->id]),
                ],
            ],
            'data' => [
                'session_id' => $this->session->id,
                'session_uuid' => $this->session->uuid,
                'agent_id' => $this->session->agent_id,
                'escalation_reason' => $this->session->escalation_reason,
            ],
        ];
    }
}
