<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Models\AiSession;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class SupportReplyMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public AiSession $session,
        public SupportMessage $message,
        public User $agent
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $agentName = $this->session->agent?->name ?? 'Support';
        $token = $this->session->support_access_token;

        // Sujet avec token pour le threading
        $subject = "[Support-{$token}] Réponse de {$agentName}";

        // Récupérer le dernier message pour In-Reply-To
        $lastUserMessage = $this->session->supportMessages()
            ->where('sender_type', 'user')
            ->where('channel', 'email')
            ->orderBy('created_at', 'desc')
            ->first();

        $inReplyTo = $lastUserMessage?->email_metadata['message_id'] ?? null;

        return new Envelope(
            from: new Address(
                $this->session->agent?->support_email ?? config('mail.from.address'),
                $agentName
            ),
            subject: $subject,
            replyTo: [
                new Address(
                    $this->session->agent?->support_email ?? config('mail.from.address'),
                    $agentName
                ),
            ],
        );
    }

    /**
     * Get the message headers.
     */
    public function headers(): Headers
    {
        $token = $this->session->support_access_token;

        // Générer un Message-ID unique avec le token
        $messageId = "support-{$token}-" . time() . '@' . parse_url(config('app.url'), PHP_URL_HOST);

        $headers = [
            'Message-ID' => "<{$messageId}>",
        ];

        // Ajouter In-Reply-To si on répond à un email
        $lastUserMessage = $this->session->supportMessages()
            ->where('sender_type', 'user')
            ->where('channel', 'email')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastUserMessage?->email_metadata['message_id'] ?? null) {
            $headers['In-Reply-To'] = $lastUserMessage->email_metadata['message_id'];
            $headers['References'] = $lastUserMessage->email_metadata['message_id'];
        }

        return new Headers(
            text: $headers,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.reply',
            with: [
                'session' => $this->session,
                'message' => $this->message,
                'agent' => $this->agent,
                'agentName' => $this->session->agent?->name ?? 'Support',
                'replyInstructions' => $this->getReplyInstructions(),
            ],
        );
    }

    /**
     * Instructions pour répondre.
     */
    protected function getReplyInstructions(): string
    {
        return "Pour répondre, vous pouvez simplement répondre à cet email. " .
               "Votre message sera automatiquement traité.";
    }
}
