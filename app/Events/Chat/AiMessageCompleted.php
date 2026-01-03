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
 * Événement broadcasté quand un message IA est complété.
 * Utilisé pour le chat public (sans authentification).
 * Utilise ShouldBroadcastNow pour un envoi synchrone.
 */
class AiMessageCompleted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public AiMessage $message
    ) {}

    /**
     * Canal de diffusion public basé sur l'UUID du message.
     * Sécurisé car l'UUID n'est connu que du client qui a envoyé le message.
     *
     * IMPORTANT: Quand le support humain est actif (escalated/assigned),
     * on ne broadcast PAS au standalone pour éviter que l'utilisateur
     * voie les réponses non validées.
     */
    public function broadcastOn(): array
    {
        $session = $this->message->session;
        $channels = [];

        // Canal pour l'admin (toujours inclus)
        $channels[] = new Channel("chat.session.{$session->uuid}");

        // Canal pour le standalone (seulement si PAS en mode support humain)
        // Quand support humain actif, l'admin doit valider avant que l'utilisateur voie
        if (!$this->isHumanSupportActive()) {
            $channels[] = new Channel("chat.message.{$this->message->uuid}");
        }

        return $channels;
    }

    /**
     * Vérifie si le support humain est actif sur cette session
     */
    private function isHumanSupportActive(): bool
    {
        $session = $this->message->session;

        // Support actif si la session est escaladée ou assignée
        return in_array($session->support_status, ['escalated', 'assigned']);
    }

    /**
     * Nom de l'événement côté client.
     */
    public function broadcastAs(): string
    {
        return 'completed';
    }

    /**
     * Données envoyées au client.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->uuid,
            'status' => 'completed',
            'content' => $this->message->content,
            'model' => $this->message->model_used,
            'generation_time_ms' => $this->message->generation_time_ms,
            'tokens_prompt' => $this->message->tokens_prompt,
            'tokens_completion' => $this->message->tokens_completion,
            'created_at' => $this->message->created_at?->toISOString(),
            'needs_validation' => $this->isHumanSupportActive(),
        ];
    }
}
