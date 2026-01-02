<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Agent;
use App\Models\AiSession;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

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
        $config = $agent->getImapConfig();

        if (!$config || !$this->isConfigValid($config)) {
            Log::warning('IMAP config missing or invalid for agent', [
                'agent_id' => $agent->id,
            ]);
            return [];
        }

        try {
            $client = $this->connect($config);

            if (!$client) {
                Log::error('Failed to connect to IMAP server', [
                    'agent_id' => $agent->id,
                ]);
                return [];
            }

            $folder = $client->getFolder($config['folder'] ?? 'INBOX');

            if (!$folder) {
                Log::error('Failed to open IMAP folder', [
                    'agent_id' => $agent->id,
                    'folder' => $config['folder'] ?? 'INBOX',
                ]);
                $client->disconnect();
                return [];
            }

            // Récupérer les emails non lus des 7 derniers jours
            $since = now()->subDays(7);
            $messages = $folder->query()
                ->unseen()
                ->since($since)
                ->get();

            if ($messages->count() === 0) {
                $client->disconnect();
                Log::debug('No new emails found', ['agent_id' => $agent->id]);
                return [];
            }

            $processedEmails = [];

            foreach ($messages as $message) {
                $result = $this->processEmail($agent, $message);
                if ($result) {
                    $processedEmails[] = $result;
                    // Marquer comme lu
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
     * Établit une connexion IMAP via webklex/php-imap.
     */
    protected function connect(array $config): ?Client
    {
        try {
            $clientManager = new ClientManager();

            $encryption = strtolower($config['encryption'] ?? 'ssl');
            $port = (int) ($config['port'] ?? 993);

            $client = $clientManager->make([
                'host' => $config['host'],
                'port' => $port,
                'encryption' => $encryption,
                'validate_cert' => $config['validate_cert'] ?? false,
                'username' => $config['username'],
                'password' => $config['password'],
                'protocol' => 'imap',
                'timeout' => 30,
                'authentication' => null, // Auto-detect
            ]);

            $client->connect();

            return $client;

        } catch (\Throwable $e) {
            Log::error('IMAP connection failed', [
                'host' => $config['host'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Traite un email entrant.
     */
    protected function processEmail(Agent $agent, Message $message): ?array
    {
        try {
            $subject = $message->getSubject()?->toString() ?? '';
            $from = $message->getFrom()[0]?->mail ?? '';
            $messageId = $message->getMessageId()?->toString();
            $inReplyTo = $message->getInReplyTo()?->toString();
            $references = $message->getReferences()?->toString();

            // Récupérer le corps du message (préférer texte brut, sinon HTML)
            $body = $message->getTextBody();
            if (empty($body)) {
                $body = $message->getHTMLBody();
            }

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
            $isHtml = empty($message->getTextBody()) && !empty($message->getHTMLBody());
            $cleanedBody = $this->emailParser->parse($body ?? '', $isHtml);

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

        } catch (\Throwable $e) {
            Log::error('Failed to process email', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrait le token de session de l'email.
     */
    protected function extractSessionToken(string $subject, ?string $inReplyTo, ?string $references): ?string
    {
        // Pattern: [Support-TOKENXXXXXX] dans le sujet (ancien format)
        if (preg_match('/\[Support-([a-zA-Z0-9]{64})\]/', $subject, $matches)) {
            return $matches[1];
        }

        // Pattern: [Réf: XXXXXX] dans le sujet (nouveau format court)
        // On doit retrouver la session par les 6 derniers caractères
        if (preg_match('/\[Réf:\s*([A-Z0-9]{6})\]/i', $subject, $matches)) {
            $shortRef = strtoupper($matches[1]);
            // Chercher une session dont le token se termine par cette référence
            $session = AiSession::whereRaw('UPPER(RIGHT(support_access_token, 6)) = ?', [$shortRef])
                ->whereNotNull('support_access_token')
                ->first();
            if ($session) {
                return $session->support_access_token;
            }
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
    public function testConnection(array $config): array
    {
        try {
            $client = $this->connect($config);

            if ($client) {
                // Essayer d'ouvrir le dossier
                $folder = $client->getFolder($config['folder'] ?? 'INBOX');
                $folderExists = $folder !== null;

                $client->disconnect();

                return [
                    'success' => true,
                    'message' => $folderExists
                        ? 'Connexion IMAP réussie'
                        : 'Connexion réussie mais dossier introuvable',
                ];
            }

            return [
                'success' => false,
                'message' => 'Impossible de se connecter au serveur IMAP',
            ];

        } catch (\Throwable $e) {
            Log::error('IMAP connection test failed', [
                'host' => $config['host'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $this->parseImapError($e->getMessage()),
                'raw_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse les erreurs IMAP en messages lisibles.
     */
    protected function parseImapError(string $error): string
    {
        $error = strtolower($error);

        if (str_contains($error, 'authentication failed') || str_contains($error, 'login failed')) {
            return 'Authentification échouée. Vérifiez l\'identifiant et le mot de passe.';
        }

        if (str_contains($error, 'connection refused')) {
            return 'Connexion refusée. Vérifiez l\'adresse du serveur et le port.';
        }

        if (str_contains($error, 'connection timed out') || str_contains($error, 'timeout')) {
            return 'Délai de connexion dépassé. Le serveur ne répond pas.';
        }

        if (str_contains($error, 'certificate') || str_contains($error, 'ssl')) {
            return 'Erreur de certificat SSL. Essayez de désactiver la validation du certificat.';
        }

        if (str_contains($error, 'unknown host') || str_contains($error, 'getaddrinfo')) {
            return 'Serveur introuvable. Vérifiez l\'adresse du serveur IMAP.';
        }

        return 'Erreur de connexion IMAP: ' . $error;
    }
}
