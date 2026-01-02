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

/**
 * Email envoyé aux agents support quand un nouveau message arrive
 * et qu'ils ne sont pas connectés au backoffice.
 */
class NewMessageNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public AiSession $session,
        public string $messagePreview,
        public string $senderName,
    ) {}

    public function envelope(): Envelope
    {
        $agentName = $this->session->agent?->name ?? 'Support';

        return new Envelope(
            subject: "Nouveau message - {$agentName} [Session #{$this->session->id}]",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.new-message-notification',
            with: [
                'session' => $this->session,
                'messagePreview' => $this->messagePreview,
                'senderName' => $this->senderName,
                'agentName' => $this->session->agent?->name ?? 'Support',
                'backofficeUrl' => route('filament.admin.resources.ai-sessions.view', ['record' => $this->session->id]),
            ],
        );
    }
}
