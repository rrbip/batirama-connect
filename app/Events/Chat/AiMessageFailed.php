<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\AiMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Événement broadcasté quand un message IA échoue.
 * Utilisé pour le chat public (sans authentification).
 */
class AiMessageFailed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AiMessage $message
    ) {}

    /**
     * Canal de diffusion public basé sur l'UUID du message.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("chat.message.{$this->message->uuid}"),
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
