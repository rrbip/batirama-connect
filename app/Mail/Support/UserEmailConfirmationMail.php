<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Models\AiSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserEmailConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $agentName;
    public string $supportEmail;
    public string $reference;

    public function __construct(
        public AiSession $session
    ) {
        $agent = $session->agent;
        $this->agentName = $agent?->name ?? 'Support';
        $this->supportEmail = $agent?->support_email ?? config('mail.from.address');

        // Générer la référence à partir du support_access_token (6 derniers caractères)
        $this->reference = $session->support_access_token
            ? strtoupper(substr($session->support_access_token, -6))
            : strtoupper(substr($session->uuid, -6));
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Votre demande de support a bien été enregistrée - {$this->agentName} [Réf: {$this->reference}]",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.user-email-confirmation',
            with: [
                'agentName' => $this->agentName,
                'supportEmail' => $this->supportEmail,
                'userName' => $this->session->user?->name ?? 'Bonjour',
                'reference' => $this->reference,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
