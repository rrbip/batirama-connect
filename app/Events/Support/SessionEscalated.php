<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\AiSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionEscalated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AiSession $session
    ) {}

    /**
     * Canaux de diffusion : agents de support et canal général de l'agent IA.
     */
    public function broadcastOn(): array
    {
        return [
            // Canal pour tous les agents de support de cet agent IA
            new PrivateChannel("agent.{$this->session->agent_id}.support"),
            // Canal pour la session spécifique (côté utilisateur)
            new PrivateChannel("session.{$this->session->uuid}"),
        ];
    }

    /**
     * Nom de l'événement côté client.
     */
    public function broadcastAs(): string
    {
        return 'session.escalated';
    }

    /**
     * Données envoyées au client.
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'session_uuid' => $this->session->uuid,
            'agent_id' => $this->session->agent_id,
            'agent_name' => $this->session->agent?->name,
            'user_name' => $this->session->user?->name ?? 'Visiteur',
            'user_email' => $this->session->user_email,
            'escalation_reason' => $this->session->escalation_reason,
            'escalated_at' => $this->session->escalated_at?->toISOString(),
            'message_count' => $this->session->message_count,
        ];
    }
}
