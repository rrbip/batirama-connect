<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\AiMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Événement broadcasté quand un message IA échoue.
 * Utilisé pour le chat public (sans authentification).
 * Utilise ShouldBroadcastNow pour un envoi synchrone.
 */
class AiMessageFailed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AiMessage $message
    ) {}

    /**
     * Canal de diffusion public basé sur l'UUID du message.
     * Broadcast aussi sur le canal session pour l'admin.
     */
    public function broadcastOn(): array
    {
        $session = $this->message->session;

        return [
            new Channel("chat.message.{$this->message->uuid}"),
            new Channel("chat.session.{$session->uuid}"),
        ];
    }

    /**
     * Nom de l'événement côté client.
     */
    public function broadcastAs(): string
    {
        return 'failed';
    }

    /**
     * Données envoyées au client.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->uuid,
            'status' => 'failed',
            'error' => $this->message->processing_error ?? 'Une erreur est survenue',
            'can_retry' => true,
            'retry_count' => $this->message->retry_count,
        ];
    }
}
