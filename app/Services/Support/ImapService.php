<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Agent;
use App\Models\AiSession;
use Illuminate\Support\Facades\Log;

class ImapService
{
    protected SupportService $supportService;
    protected EmailReplyParser $emailParser;

    public function __construct(SupportService $supportService, EmailReplyParser $emailParser)
    {
        $this->supportService = $supportService;
        $this->emailParser = $emailParser;
    }

    /**
     * Récupère les nouveaux emails pour un agent IA.
     */
    public function fetchNewEmails(Agent $agent): array
    {
        if (!function_exists('imap_open')) {
            Log::error('PHP IMAP extension is not installed');
            return [];
        }

        $config = $agent->getImapConfig();

        if (!$config || !$this->isConfigValid($config)) {
            Log::warning('IMAP config missing or invalid for agent', [
                'agent_id' => $agent->id,
            ]);
            return [];
        }

        try {
            $connection = $this->connect($config);

            if (!$connection) {
                Log::error('Failed to connect to IMAP server', [
                    'agent_id' => $agent->id,
                    'error' => imap_last_error(),
                ]);
                return [];
            }

            // Récupérer les emails non lus des 7 derniers jours
            $since = date('d-M-Y', strtotime('-7 days'));
            $emails = imap_search($connection, 'UNSEEN SINCE "' . $since . '"');

            if (!$emails) {
                imap_close($connection);
                Log::debug('No new emails found', ['agent_id' => $agent->id]);
                return [];
            }

            $processedEmails = [];

            foreach ($emails as $emailNum) {
                $result = $this->processEmail($agent, $connection, $emailNum);
                if ($result) {
                    $processedEmails[] = $result;
                    // Marquer comme lu
                    imap_setflag_full($connection, (string) $emailNum, '\\Seen');
                }
            }

            imap_close($connection);

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
     * Établit une connexion IMAP.
     */
    protected function connect(array $config): mixed
    {
        $encryption = strtolower($config['encryption'] ?? 'ssl');
        $port = (int) ($config['port'] ?? 993);
        $folder = $config['folder'] ?? 'INBOX';

        $flags = '/imap';
        if ($encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls';
        }
        $flags .= '/novalidate-cert';

        $mailbox = '{' . $config['host'] . ':' . $port . $flags . '}' . $folder;

        return @imap_open(
            $mailbox,
            $config['username'],
            $config['password'],
            0,
            1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
        );
    }

    /**
     * Traite un email entrant.
     */
    protected function processEmail(Agent $agent, mixed $connection, int $emailNum): ?array
    {
        $header = imap_headerinfo($connection, $emailNum);
        $structure = imap_fetchstructure($connection, $emailNum);

        if (!$header) {
            return null;
        }

        $subject = $this->decodeHeader($header->subject ?? '');
        $from = $header->from[0]->mailbox . '@' . $header->from[0]->host;
        $messageId = $header->message_id ?? null;
        $inReplyTo = $header->in_reply_to ?? null;
        $references = $header->references ?? null;

        // Récupérer le corps du message
        $body = $this->getBody($connection, $emailNum, $structure);

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
        $cleanedBody = $this->emailParser->parse($body, str_contains($body, '<'));

        if (empty(trim($cleanedBody))) {
            Log::debug('Empty email body after parsing', [
                'session_id' => $session->id,
            ]);
            return null;
        }

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

        return [
            'session_id' => $session->id,
            'message_id' => $supportMessage->id,
            'from' => $from,
            'subject' => $subject,
        ];
    }

    /**
     * Récupère le corps du message.
     */
    protected function getBody(mixed $connection, int $emailNum, object $structure): string
    {
        $body = '';

        if ($structure->type === 0) {
            // Simple message
            $body = imap_fetchbody($connection, $emailNum, '1');
            $body = $this->decodeBody($body, $structure->encoding ?? 0);
        } elseif ($structure->type === 1) {
            // Multipart
            foreach ($structure->parts as $partNum => $part) {
                if ($part->subtype === 'PLAIN') {
                    $body = imap_fetchbody($connection, $emailNum, (string) ($partNum + 1));
                    $body = $this->decodeBody($body, $part->encoding ?? 0);
                    break;
                }
                if ($part->subtype === 'HTML' && empty($body)) {
                    $body = imap_fetchbody($connection, $emailNum, (string) ($partNum + 1));
                    $body = $this->decodeBody($body, $part->encoding ?? 0);
                }
            }
        }

        // Convertir le charset si nécessaire
        if (isset($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset' && strtolower($param->value) !== 'utf-8') {
                    $body = mb_convert_encoding($body, 'UTF-8', $param->value);
                }
            }
        }

        return $body;
    }

    /**
     * Décode le corps selon l'encodage.
     */
    protected function decodeBody(string $body, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($body), // BASE64
            4 => quoted_printable_decode($body), // QUOTED-PRINTABLE
            default => $body,
        };
    }

    /**
     * Décode un header MIME.
     */
    protected function decodeHeader(string $header): string
    {
        $elements = imap_mime_header_decode($header);
        $decoded = '';
        foreach ($elements as $element) {
            $decoded .= $element->text;
        }
        return $decoded;
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
     * Teste la connexion IMAP.
     */
    public function testConnection(array $config): bool
    {
        if (!function_exists('imap_open')) {
            return false;
        }

        try {
            $connection = $this->connect($config);
            if ($connection) {
                imap_close($connection);
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            Log::error('IMAP connection test failed', [
                'host' => $config['host'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
