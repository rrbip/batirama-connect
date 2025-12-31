<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Agent;
use App\Models\AiSession;
use App\Models\SupportMessage;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client;

class ImapService
{
    protected SupportService $supportService;

    public function __construct(SupportService $supportService)
    {
        $this->supportService = $supportService;
    }

    /**
     * Récupère les nouveaux emails pour un agent IA.
     */
    public function fetchNewEmails(Agent $agent): array
    {
        $config = $agent->getImapConfig();

        if (!$config || !$this->isConfigValid($config)) {
            Log::warning('IMAP config missing or invalid for agent', [
                'agent_id' => $agent->id,
            ]);
            return [];
        }

        try {
            $client = $this->createClient($config);
            $client->connect();

            $folder = $client->getFolder($config['folder'] ?? 'INBOX');
            $messages = $folder->query()
                ->unseen()
                ->since(now()->subDays(7))
                ->get();

            $processedEmails = [];

            foreach ($messages as $message) {
                $result = $this->processEmail($agent, $message);
                if ($result) {
                    $processedEmails[] = $result;
                    // Marquer comme lu après traitement
                    $message->setFlag('Seen');
                }
            }

            $client->disconnect();

            Log::info('IMAP fetch completed', [
                'agent_id' => $agent->id,
                'emails_processed' => count($processedEmails),
            ]);

            return $processedEmails;

        } catch (\Throwable $e) {
            Log::error('IMAP fetch failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Traite un email entrant.
     */
    protected function processEmail(Agent $agent, $message): ?array
    {
        $subject = $message->getSubject();
        $body = $message->getTextBody() ?? $message->getHTMLBody();
        $from = $message->getFrom()[0]?->mail ?? null;
        $messageId = $message->getMessageId();
        $inReplyTo = $message->getInReplyTo();
        $references = $message->getReferences();

        // Chercher le token de session dans le sujet ou les headers
        $sessionToken = $this->extractSessionToken($subject, $inReplyTo, $references);

        if (!$sessionToken) {
            Log::debug('No session token found in email', [
                'subject' => $subject,
                'from' => $from,
            ]);
            return null;
        }

        // Trouver la session
        $session = AiSession::where('support_access_token', $sessionToken)
            ->where('agent_id', $agent->id)
            ->first();

        if (!$session) {
            Log::warning('Session not found for email token', [
                'token' => $sessionToken,
                'from' => $from,
            ]);
            return null;
        }

        // Vérifier que le token n'a pas expiré
        if ($session->support_token_expires_at && $session->support_token_expires_at->isPast()) {
            Log::warning('Session token expired', [
                'session_id' => $session->id,
                'expired_at' => $session->support_token_expires_at,
            ]);
            return null;
        }

        // Nettoyer le contenu de l'email
        $cleanedBody = $this->cleanEmailBody($body);

        // Créer le message de support
        $supportMessage = $this->supportService->receiveUserMessage(
            $session,
            $cleanedBody,
            'email',
            [
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo,
                'from' => $from,
                'subject' => $subject,
            ]
        );

        // Traiter les pièces jointes
        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => $attachment->getName(),
                'mime' => $attachment->getMimeType(),
                'size' => $attachment->getSize(),
                'content' => $attachment->getContent(),
            ];
        }

        return [
            'session_id' => $session->id,
            'message_id' => $supportMessage->id,
            'from' => $from,
            'subject' => $subject,
            'attachments_count' => count($attachments),
        ];
    }

    /**
     * Extrait le token de session de l'email.
     */
    protected function extractSessionToken(string $subject, ?string $inReplyTo, ?string $references): ?string
    {
        // Pattern: [Support-TOKENXXXXXX] dans le sujet
        if (preg_match('/\[Support-([a-zA-Z0-9]{64})\]/', $subject, $matches)) {
            return $matches[1];
        }

        // Chercher dans References ou In-Reply-To
        $haystack = ($inReplyTo ?? '') . ' ' . ($references ?? '');
        if (preg_match('/support-([a-zA-Z0-9]{64})@/', $haystack, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Nettoie le corps de l'email (retire les citations, signatures, etc.).
     */
    protected function cleanEmailBody(string $body): string
    {
        // Supprimer le HTML si présent
        if (str_contains($body, '<html') || str_contains($body, '<body')) {
            $body = strip_tags($body);
        }

        // Décoder les entités HTML
        $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');

        // Supprimer les citations (lignes commençant par >)
        $lines = explode("\n", $body);
        $cleanLines = [];
        $inQuote = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Détecter le début de la citation
            if (preg_match('/^(>|Le .+ a écrit|On .+ wrote|From:|Sent:|De :|Envoyé :)/i', $trimmed)) {
                $inQuote = true;
                continue;
            }

            // Détecter les séparateurs de signature
            if (preg_match('/^(-{2,}|_{2,}|Cordialement|Best regards|Sent from my)/i', $trimmed)) {
                break;
            }

            if (!$inQuote) {
                $cleanLines[] = $line;
            }
        }

        $body = implode("\n", $cleanLines);

        // Nettoyer les espaces multiples
        $body = preg_replace('/\n{3,}/', "\n\n", $body);

        return trim($body);
    }

    /**
     * Vérifie si la configuration IMAP est valide.
     */
    protected function isConfigValid(?array $config): bool
    {
        return $config
            && !empty($config['host'])
            && !empty($config['username'])
            && !empty($config['password']);
    }

    /**
     * Crée un client IMAP avec la configuration.
     */
    protected function createClient(array $config): \Webklex\IMAP\Client
    {
        return Client::make([
            'host' => $config['host'],
            'port' => $config['port'] ?? 993,
            'encryption' => $config['encryption'] ?? 'ssl',
            'validate_cert' => $config['validate_cert'] ?? true,
            'username' => $config['username'],
            'password' => $config['password'],
            'protocol' => 'imap',
        ]);
    }

    /**
     * Teste la connexion IMAP.
     */
    public function testConnection(array $config): bool
    {
        try {
            $client = $this->createClient($config);
            $client->connect();
            $client->disconnect();
            return true;
        } catch (\Throwable $e) {
            Log::error('IMAP connection test failed', [
                'host' => $config['host'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
