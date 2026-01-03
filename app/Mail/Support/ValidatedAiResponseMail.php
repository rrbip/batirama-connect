<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Models\AiMessage;
use App\Models\AiSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Email envoyé quand une réponse IA est validée par un admin.
 *
 * Ce mail est envoyé directement (sans ShouldQueue) car c'est une action
 * synchrone depuis l'interface admin.
 */
class ValidatedAiResponseMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public AiSession $session,
        public AiMessage $aiMessage
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $brandName = $this->getBrandName();

        // Référence courte pour le sujet (6 derniers caractères du token)
        $shortRef = strtoupper(substr($this->session->support_access_token, -6));

        // Sujet propre avec référence courte pour le threading
        $subject = "Réponse du support {$brandName} [Réf: {$shortRef}]";

        return new Envelope(
            from: new Address(
                $this->session->agent?->support_email ?? config('mail.from.address'),
                $brandName
            ),
            subject: $subject,
            replyTo: [
                new Address(
                    $this->session->agent?->support_email ?? config('mail.from.address'),
                    $brandName
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

        // Générer un Message-ID unique avec le token (permet le threading côté client)
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
                'message' => $this->createMessageProxy(),
                'agent' => $this->createAgentProxy(),
                'brandName' => $this->getBrandName(),
                'footerText' => $this->getFooterText(),
                'chatUrl' => $this->getChatUrl(),
                'replyInstructions' => $this->getReplyInstructions(),
            ],
        );
    }

    /**
     * Crée un proxy pour le message compatible avec le template.
     * Utilise le contenu corrigé si disponible (bouton "Corriger").
     */
    protected function createMessageProxy(): object
    {
        return (object) [
            'content' => $this->aiMessage->corrected_content ?? $this->aiMessage->content,
        ];
    }

    /**
     * Crée un proxy pour l'agent compatible avec le template.
     */
    protected function createAgentProxy(): object
    {
        $agentName = $this->session->agent?->name ?? 'Assistant IA';

        return (object) [
            'name' => $agentName,
        ];
    }

    /**
     * Nom de la marque (configurable dans l'agent).
     */
    protected function getBrandName(): string
    {
        $config = $this->session->agent?->ai_assistance_config ?? [];

        // Priorité: email_brand_name > agent name > 'Support Client'
        return $config['email_brand_name']
            ?? $this->session->agent?->name
            ?? 'Support Client';
    }

    /**
     * Texte du footer (configurable dans l'agent).
     */
    protected function getFooterText(): ?string
    {
        $config = $this->session->agent?->ai_assistance_config ?? [];

        return $config['email_footer_text'] ?? null;
    }

    /**
     * URL pour retourner au chat.
     */
    protected function getChatUrl(): ?string
    {
        // Essayer de récupérer le token public de la session
        $publicToken = $this->session->publicAccessToken?->token;

        if ($publicToken) {
            return config('app.url') . '/c/' . $publicToken;
        }

        // Fallback: pas de lien si pas de token public
        return null;
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
