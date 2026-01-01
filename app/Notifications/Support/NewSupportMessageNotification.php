<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Models\AiSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notification envoyée quand un nouveau message arrive dans une session escaladée.
 */
class NewSupportMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AiSession $session,
        public string $senderType = 'user',
        public ?string $messagePreview = null
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
        $preview = $this->messagePreview
            ? \Illuminate\Support\Str::limit($this->messagePreview, 80)
            : 'Nouveau message';

        $title = $this->senderType === 'user'
            ? "Message de {$userName}"
            : "Nouveau message support";

        return [
            'title' => "{$title} - {$agentName}",
            'body' => $preview,
            'icon' => 'heroicon-o-chat-bubble-bottom-center-text',
            'iconColor' => 'info',
            'actions' => [
                [
                    'name' => 'view',
                    'label' => 'Répondre',
                    'url' => route('filament.admin.resources.ai-sessions.view', ['record' => $this->session->id]),
                ],
            ],
            'data' => [
                'session_id' => $this->session->id,
                'session_uuid' => $this->session->uuid,
                'sender_type' => $this->senderType,
            ],
        ];
    }
}
