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
 * Événement broadcasté quand un message IA est validé par un admin.
 * Envoie la réponse au standalone seulement après validation.
 */
class AiMessageValidated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AiMessage $message
    ) {}

    /**
     * Broadcast sur le canal de la session pour que le standalone reçoive
     * la réponse validée de l'IA.
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
        return 'message.validated';
    }

    /**
     * Données envoyées au client.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->uuid,
            'content' => $this->message->corrected_content ?? $this->message->content,
            'model' => $this->message->model_used,
            'created_at' => $this->message->created_at?->toISOString(),
            'validated_at' => $this->message->validated_at?->toISOString(),
        ];
    }
}
