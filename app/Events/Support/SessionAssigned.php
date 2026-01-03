<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\AiSession;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionAssigned implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AiSession $session,
        public User $supportAgent
    ) {}

    /**
     * Canaux de diffusion.
     */
    public function broadcastOn(): array
    {
        return [
            // Canal pour l'agent de support assigné
            new PrivateChannel("user.{$this->supportAgent->id}"),
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
        return 'session.assigned';
    }

    /**
     * Données envoyées au client.
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'session_uuid' => $this->session->uuid,
            'support_agent' => [
                'id' => $this->supportAgent->id,
                'name' => $this->supportAgent->name,
            ],
            'assigned_at' => $this->session->assigned_at?->toISOString(),
        ];
    }
}
