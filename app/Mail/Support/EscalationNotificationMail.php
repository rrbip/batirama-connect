<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Models\AiSession;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EscalationNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public AiSession $session,
        public User $supportAgent
    ) {
        $this->onQueue('mail');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $agentName = $this->session->agent?->name ?? 'Agent IA';
        $userName = $this->session->user?->name ?? $this->session->user_email ?? 'Visiteur';

        return new Envelope(
            subject: "[Support] Nouvelle demande d'assistance - {$agentName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.escalation-notification',
            with: [
                'session' => $this->session,
                'supportAgent' => $this->supportAgent,
                'agentName' => $this->session->agent?->name ?? 'Agent IA',
                'userName' => $this->session->user?->name ?? $this->session->user_email ?? 'Visiteur',
                'escalationReason' => $this->getEscalationReasonLabel(),
                'lastMessages' => $this->getLastMessages(),
                'takeOverUrl' => $this->getTakeOverUrl(),
            ],
        );
    }

    /**
     * Retourne le libellé de la raison d'escalade.
     */
    protected function getEscalationReasonLabel(): string
    {
        return match ($this->session->escalation_reason) {
            'low_confidence' => "L'IA n'a pas trouvé de réponse fiable (score RAG insuffisant)",
            'user_request' => "L'utilisateur a demandé à parler à un humain",
            'ai_uncertainty' => "L'IA a signalé son incertitude",
            'negative_feedback' => "L'utilisateur a donné un feedback négatif",
            default => 'Escalade manuelle',
        };
    }

    /**
     * Récupère les derniers messages de la conversation.
     */
    protected function getLastMessages(): array
    {
        return $this->session->messages()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->reverse()
            ->map(fn ($msg) => [
                'role' => $msg->role,
                'content' => \Illuminate\Support\Str::limit($msg->content, 200),
                'created_at' => $msg->created_at?->format('H:i'),
            ])
            ->toArray();
    }

    /**
     * Génère l'URL pour prendre en charge la session.
     */
    protected function getTakeOverUrl(): string
    {
        return route('filament.admin.resources.ai-sessions.view', [
            'record' => $this->session->id,
        ]);
    }
}
