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
 * Événement broadcasté quand un message utilisateur est reçu.
 * Permet à l'admin de voir le message immédiatement.
 * Utilise ShouldBroadcastNow pour un envoi synchrone sans queue.
 */
class UserMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AiMessage $message
    ) {}

    /**
     * Canal de diffusion basé sur l'UUID de la session.
     * L'admin s'abonne au canal de session pour voir tous les messages.
     */
    public function broadcastOn(): array
    {
        $session = $this->message->session;

        return [
            new Channel("chat.session.{$session->uuid}"),
        ];
    }

    /**
     * Nom de l'événement côté client.
     */
    public function broadcastAs(): string
    {
        return 'user.message';
    }

    /**
     * Données envoyées au client.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->uuid,
            'session_id' => $this->message->session->uuid,
            'role' => 'user',
            'content' => $this->message->content,
            'created_at' => $this->message->created_at?->toISOString(),
        ];
    }
}
