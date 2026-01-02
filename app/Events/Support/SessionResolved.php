<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\AiSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionResolved implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AiSession $session
    ) {}

    /**
     * Canaux de diffusion.
     */
    public function broadcastOn(): array
    {
        return [
            // Canal public pour la session (standalone)
            new Channel("chat.session.{$this->session->uuid}"),
            // Canal général de l'agent IA (pour mettre à jour la liste)
            new PrivateChannel("agent.{$this->session->agent_id}.support"),
        ];
    }

    /**
     * Nom de l'événement côté client.
     */
    public function broadcastAs(): string
    {
        return 'session.resolved';
    }

    /**
     * Données envoyées au client.
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'session_uuid' => $this->session->uuid,
            'resolution_type' => $this->session->resolution_type,
            'resolved_at' => $this->session->resolved_at?->toISOString(),
            'resolved_by' => $this->session->supportAgent?->name ?? 'Agent',
        ];
    }
}
