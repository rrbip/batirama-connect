<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewSupportMessage implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public SupportMessage $message
    ) {}

    /**
     * Canaux de diffusion.
     */
    public function broadcastOn(): array
    {
        $session = $this->message->session;

        $channels = [
            // Canal de la session (les deux parties)
            new PrivateChannel("session.{$session->uuid}"),
        ];

        // Si c'est un message utilisateur, notifier l'agent de support
        if ($this->message->sender_type === 'user' && $session->support_agent_id) {
            $channels[] = new PrivateChannel("user.{$session->support_agent_id}");
        }

        // Si c'est un message agent, notifier le canal support de l'agent IA
        if ($this->message->sender_type === 'agent') {
            $channels[] = new PrivateChannel("agent.{$session->agent_id}.support");
        }

        return $channels;
    }

    /**
     * Nom de l'événement côté client.
     */
    public function broadcastAs(): string
    {
        return 'message.new';
    }

    /**
     * Données envoyées au client.
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'uuid' => $this->message->uuid,
                'session_id' => $this->message->session_id,
                'sender_type' => $this->message->sender_type,
                'sender_name' => $this->message->getSenderName(),
                'channel' => $this->message->channel,
                'content' => $this->message->content,
                'was_ai_improved' => $this->message->was_ai_improved,
                'created_at' => $this->message->created_at?->toISOString(),
                'attachments' => $this->message->attachments->map(fn ($att) => [
                    'id' => $att->id,
                    'uuid' => $att->uuid,
                    'name' => $att->original_name,
                    'size' => $att->getFormattedSize(),
                    'is_image' => $att->isImage(),
                    'icon' => $att->getIcon(),
                ])->toArray(),
            ],
        ];
    }
}
