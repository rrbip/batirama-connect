<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class EmailConfigTestService
{
    /**
     * Résultat du test SMTP.
     */
    public ?array $smtpResult = null;

    /**
     * Résultat du test IMAP.
     */
    public ?array $imapResult = null;

    /**
     * ID unique du message de test.
     */
    protected string $testMessageId;

    /**
     * Génère un rapport de diagnostic complet et formaté.
     */
    public function generateReport(array $smtpConfig, ?array $imapConfig, string $testEmail, array $results): string
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $phpVersion = PHP_VERSION;
        $osInfo = php_uname('s') . ' ' . php_uname('r');

        $report = [];
        $report[] = "╔══════════════════════════════════════════════════════════════╗";
        $report[] = "║           RAPPORT DE TEST EMAIL - BATIRAMA CONNECT           ║";
        $report[] = "╚══════════════════════════════════════════════════════════════╝";
        $report[] = "";
        $report[] = "Date du test : {$timestamp}";
        $report[] = "PHP Version  : {$phpVersion}";
        $report[] = "Système      : {$osInfo}";
        $report[] = "";
        $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $report[] = "CONFIGURATION SMTP";
        $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $report[] = "Serveur      : " . ($smtpConfig['host'] ?? 'Non configuré');
        $report[] = "Port         : " . ($smtpConfig['port'] ?? 'Non configuré');
        $report[] = "Chiffrement  : " . strtoupper($smtpConfig['encryption'] ?? 'Non configuré');
        $report[] = "Identifiant  : " . ($smtpConfig['username'] ?? 'Non configuré');
        $report[] = "Mot de passe : " . (isset($smtpConfig['password']) ? str_repeat('*', min(strlen($smtpConfig['password']), 8)) : 'Non configuré');
        $report[] = "Expéditeur   : " . ($smtpConfig['from_address'] ?? $smtpConfig['username'] ?? 'Non configuré');
        $report[] = "Destinataire : {$testEmail}";
        $report[] = "";

        if ($imapConfig) {
            $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            $report[] = "CONFIGURATION IMAP";
            $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            $report[] = "Serveur      : " . ($imapConfig['host'] ?? 'Non configuré');
            $report[] = "Port         : " . ($imapConfig['port'] ?? '993');
            $report[] = "Chiffrement  : " . strtoupper($imapConfig['encryption'] ?? 'ssl');
            $report[] = "Identifiant  : " . ($imapConfig['username'] ?? 'Non configuré');
            $report[] = "Dossier      : " . ($imapConfig['folder'] ?? 'INBOX');
            $report[] = "";
        }

        // Résultat SMTP
        $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $report[] = "RÉSULTAT TEST SMTP";
        $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $smtpResult = $results['smtp'] ?? [];
        $smtpSuccess = $smtpResult['success'] ?? false;
        $report[] = "Statut       : " . ($smtpSuccess ? "✅ SUCCÈS" : "❌ ÉCHEC");
        $report[] = "Message      : " . ($smtpResult['message'] ?? 'Aucun message');

        if (!$smtpSuccess && isset($smtpResult['raw_error'])) {
            $report[] = "";
            $report[] = "Erreur brute :";
            $report[] = "┌─────────────────────────────────────────────────────────────┐";
            foreach (explode("\n", wordwrap($smtpResult['raw_error'], 60)) as $line) {
                $report[] = "│ " . str_pad($line, 60) . "│";
            }
            $report[] = "└─────────────────────────────────────────────────────────────┘";
        }

        if (isset($smtpResult['test_id'])) {
            $report[] = "ID du test   : " . $smtpResult['test_id'];
        }
        $report[] = "";

        // Résultat IMAP
        if (isset($results['imap']) && !($results['imap']['skipped'] ?? false)) {
            $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            $report[] = "RÉSULTAT TEST IMAP";
            $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            $imapResult = $results['imap'];
            $imapSuccess = $imapResult['success'] ?? false;
            $report[] = "Statut       : " . ($imapSuccess ? "✅ SUCCÈS" : "❌ ÉCHEC");
            $report[] = "Message      : " . ($imapResult['message'] ?? 'Aucun message');

            if (isset($imapResult['email_found'])) {
                $report[] = "Email reçu   : " . ($imapResult['email_found'] ? "Oui" : "Non (délai possible)");
            }
            if (isset($imapResult['message_count'])) {
                $report[] = "Messages     : " . $imapResult['message_count'] . " dans la boîte";
            }

            if (!$imapSuccess && isset($imapResult['raw_error'])) {
                $report[] = "";
                $report[] = "Erreur brute :";
                $report[] = "┌─────────────────────────────────────────────────────────────┐";
                foreach (explode("\n", wordwrap($imapResult['raw_error'], 60)) as $line) {
                    $report[] = "│ " . str_pad($line, 60) . "│";
                }
                $report[] = "└─────────────────────────────────────────────────────────────┘";
            }
            $report[] = "";
        }

        // Diagnostic
        $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $report[] = "DIAGNOSTIC";
        $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        $diagnostics = $this->generateDiagnostics($smtpConfig, $imapConfig, $results);
        foreach ($diagnostics as $diag) {
            $report[] = $diag;
        }

        $report[] = "";
        $report[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $report[] = "Fin du rapport";
        $report[] = "";

        return implode("\n", $report);
    }

    /**
     * Génère des diagnostics basés sur les erreurs.
     */
    protected function generateDiagnostics(array $smtpConfig, ?array $imapConfig, array $results): array
    {
        $diagnostics = [];
        $smtpError = $results['smtp']['raw_error'] ?? '';
        $imapError = $results['imap']['raw_error'] ?? '';

        // Vérifications de base
        $diagnostics[] = "";
        $diagnostics[] = "Extension OpenSSL : " . (extension_loaded('openssl') ? "✓ Installée" : "✗ Manquante");
        $diagnostics[] = "Extension IMAP    : " . (function_exists('imap_open') ? "✓ Installée" : "✗ Manquante");
        $diagnostics[] = "";

        // Suggestions basées sur les erreurs SMTP
        if (!($results['smtp']['success'] ?? false)) {
            $diagnostics[] = "💡 SUGGESTIONS SMTP :";

            if (str_contains($smtpError, 'Authentication') || str_contains($smtpError, '535') || str_contains($smtpError, '534')) {
                $diagnostics[] = "   • Vérifiez que l'identifiant est l'adresse email COMPLÈTE";
                $diagnostics[] = "   • Vérifiez le mot de passe (celui de la boîte mail, pas du compte OVH)";
                $diagnostics[] = "   • Pour Gmail : créez un 'Mot de passe d'application'";
                $diagnostics[] = "   • Pour OVH : vérifiez que le compte email est actif";
            }

            if (str_contains($smtpError, 'Connection') || str_contains($smtpError, 'connect')) {
                $diagnostics[] = "   • Vérifiez l'adresse du serveur SMTP";
                $diagnostics[] = "   • Le port peut être bloqué par un firewall";
                $diagnostics[] = "   • Essayez un autre port (465 SSL ou 587 TLS)";
            }

            if (str_contains($smtpError, 'SSL') || str_contains($smtpError, 'TLS') || str_contains($smtpError, 'certificate')) {
                $diagnostics[] = "   • Port 465 → utilisez chiffrement SSL";
                $diagnostics[] = "   • Port 587 → utilisez chiffrement TLS";
                $diagnostics[] = "   • Le certificat du serveur peut être invalide";
            }

            // Suggestions spécifiques OVH
            $host = strtolower($smtpConfig['host'] ?? '');
            if (str_contains($host, 'ovh')) {
                $diagnostics[] = "";
                $diagnostics[] = "📧 CONFIGURATION OVH RECOMMANDÉE :";
                $diagnostics[] = "   • MX Plan : ssl0.ovh.net, port 465, SSL";
                $diagnostics[] = "   • Email Pro : pro1.mail.ovh.net, port 587, TLS";
                $diagnostics[] = "   • Exchange : ex1.mail.ovh.net, port 587, TLS";
            }
        }

        // Suggestions IMAP
        if (isset($results['imap']) && !($results['imap']['success'] ?? true) && !($results['imap']['skipped'] ?? false)) {
            $diagnostics[] = "";
            $diagnostics[] = "💡 SUGGESTIONS IMAP :";

            if (str_contains($imapError, 'Authentication') || str_contains($imapError, 'LOGIN')) {
                $diagnostics[] = "   • Mêmes identifiants que SMTP généralement";
                $diagnostics[] = "   • Vérifiez que l'accès IMAP est activé sur la boîte mail";
            }

            $host = strtolower($imapConfig['host'] ?? '');
            if (str_contains($host, 'ovh')) {
                $diagnostics[] = "";
                $diagnostics[] = "📧 CONFIGURATION IMAP OVH :";
                $diagnostics[] = "   • MX Plan : ssl0.ovh.net (ou imap.mail.ovh.net), port 993, SSL";
                $diagnostics[] = "   • Email Pro : pro1.mail.ovh.net, port 993, SSL";
            }
        }

        return $diagnostics;
    }

    /**
     * Teste la configuration email complète (SMTP + IMAP).
     *
     * @param array $smtpConfig Configuration SMTP
     * @param array $imapConfig Configuration IMAP
     * @param string $testEmail Email de destination (généralement le même que l'email de support)
     * @return array Résultats des tests
     */
    public function testFullConfiguration(array $smtpConfig, array $imapConfig, string $testEmail): array
    {
        $this->testMessageId = 'test-' . Str::random(16) . '-' . time();

        // 1. Tester l'envoi SMTP
        $this->smtpResult = $this->testSmtp($smtpConfig, $testEmail);

        // 2. Si SMTP réussit, attendre et vérifier la réception IMAP
        if ($this->smtpResult['success']) {
            // Attendre quelques secondes pour que l'email arrive
            sleep(3);
            $this->imapResult = $this->testImapReception($imapConfig);
        } else {
            $this->imapResult = [
                'success' => false,
                'message' => 'Test IMAP ignoré car l\'envoi SMTP a échoué',
                'skipped' => true,
            ];
        }

        return [
            'smtp' => $this->smtpResult,
            'imap' => $this->imapResult,
            'test_message_id' => $this->testMessageId,
        ];
    }

    /**
     * Teste uniquement la connexion SMTP en envoyant un email de test.
     */
    public function testSmtp(array $config, string $testEmail): array
    {
        try {
            // Valider la configuration
            $validation = $this->validateSmtpConfig($config);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message'],
                    'error_type' => 'validation',
                ];
            }

            // Construire le DSN Symfony Mailer
            // - smtps:// = SSL implicite (port 465)
            // - smtp:// = STARTTLS ou plain text (port 587, 25)
            $encryption = strtolower($config['encryption'] ?? 'tls');
            $port = (int) $config['port'];

            // Déterminer le schéma
            if ($encryption === 'ssl' || $port === 465) {
                $scheme = 'smtps';
                $queryParams = '';
            } else {
                $scheme = 'smtp';
                // Pour TLS (STARTTLS), on utilise smtp:// et Symfony détecte automatiquement
                // Mais on peut forcer avec verify_peer=0 si problème de certificat
                $queryParams = '';
            }

            $dsn = sprintf(
                '%s://%s:%s@%s:%d%s',
                $scheme,
                urlencode($config['username']),
                urlencode($config['password']),
                $config['host'],
                $port,
                $queryParams
            );

            Log::debug('Testing SMTP connection', [
                'host' => $config['host'],
                'port' => $port,
                'scheme' => $scheme,
                'encryption' => $encryption,
            ]);

            // Créer le transport et le mailer
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);

            // Créer l'email de test
            $email = (new Email())
                ->from($config['from_address'] ?? $config['username'])
                ->to($testEmail)
                ->subject('[TEST] Vérification configuration email - ' . $this->testMessageId)
                ->text("Ceci est un email de test automatique.\n\nID: {$this->testMessageId}\nDate: " . now()->format('d/m/Y H:i:s'))
                ->html("<p>Ceci est un email de test automatique.</p><p><strong>ID:</strong> {$this->testMessageId}<br><strong>Date:</strong> " . now()->format('d/m/Y H:i:s') . '</p>');

            // Envoyer
            $mailer->send($email);

            Log::info('SMTP test successful', [
                'host' => $config['host'],
                'to' => $testEmail,
                'test_id' => $this->testMessageId,
            ]);

            return [
                'success' => true,
                'message' => "Email de test envoyé avec succès à {$testEmail}",
                'test_id' => $this->testMessageId,
            ];

        } catch (TransportExceptionInterface $e) {
            $errorMessage = $this->parseSmtpError($e);

            Log::error('SMTP test failed', [
                'host' => $config['host'] ?? 'unknown',
                'error' => $e->getMessage(),
                'parsed_error' => $errorMessage,
            ]);

            return [
                'success' => false,
                'message' => $errorMessage,
                'error_type' => 'transport',
                'raw_error' => $e->getMessage(),
            ];

        } catch (\Throwable $e) {
            Log::error('SMTP test failed with unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Erreur inattendue: ' . $e->getMessage(),
                'error_type' => 'unexpected',
                'raw_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Teste la connexion IMAP et vérifie si l'email de test a été reçu.
     */
    public function testImapReception(array $config): array
    {
        // Vérifier si l'extension IMAP est disponible
        if (!function_exists('imap_open')) {
            return [
                'success' => false,
                'message' => 'L\'extension PHP IMAP n\'est pas installée sur le serveur.',
                'error_type' => 'extension_missing',
            ];
        }

        try {
            // Valider la configuration
            $validation = $this->validateImapConfig($config);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message'],
                    'error_type' => 'validation',
                ];
            }

            $encryption = strtolower($config['encryption'] ?? 'ssl');
            $port = (int) ($config['port'] ?? 993);
            $folder = $config['folder'] ?? 'INBOX';

            // Construire la chaîne de connexion IMAP
            $flags = '/imap';
            if ($encryption === 'ssl') {
                $flags .= '/ssl';
            } elseif ($encryption === 'tls') {
                $flags .= '/tls';
            }
            $flags .= '/novalidate-cert';

            $mailbox = '{' . $config['host'] . ':' . $port . $flags . '}' . $folder;

            Log::debug('Testing IMAP connection', [
                'host' => $config['host'],
                'port' => $port,
                'mailbox' => $mailbox,
            ]);

            // Connexion IMAP
            $connection = @imap_open(
                $mailbox,
                $config['username'],
                $config['password'],
                0,
                1, // Nombre de tentatives
                ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
            );

            if (!$connection) {
                $error = imap_last_error();
                return [
                    'success' => false,
                    'message' => $this->parseImapError($error),
                    'error_type' => 'connection',
                    'raw_error' => $error,
                ];
            }

            // Chercher l'email de test
            $emails = imap_search($connection, 'SUBJECT "' . $this->testMessageId . '"');

            $testEmailFound = !empty($emails);

            // Si trouvé, supprimer l'email de test
            if ($testEmailFound) {
                foreach ($emails as $emailNum) {
                    imap_delete($connection, $emailNum);
                }
                imap_expunge($connection);
            }

            // Récupérer quelques infos sur la boîte
            $check = imap_check($connection);
            $messageCount = $check->Nmsgs ?? 0;

            imap_close($connection);

            Log::info('IMAP test completed', [
                'host' => $config['host'],
                'test_email_found' => $testEmailFound,
                'message_count' => $messageCount,
                'test_id' => $this->testMessageId,
            ]);

            if ($testEmailFound) {
                return [
                    'success' => true,
                    'message' => "Connexion IMAP réussie et email de test reçu ({$messageCount} messages dans la boîte)",
                    'email_found' => true,
                    'message_count' => $messageCount,
                ];
            } else {
                return [
                    'success' => true,
                    'message' => "Connexion IMAP réussie ({$messageCount} messages). L'email de test n'est pas encore arrivé (peut prendre quelques secondes).",
                    'email_found' => false,
                    'warning' => true,
                    'message_count' => $messageCount,
                ];
            }

        } catch (\Throwable $e) {
            Log::error('IMAP test failed with unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $this->parseImapError($e->getMessage()),
                'error_type' => 'unexpected',
                'raw_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Teste uniquement la connexion IMAP (sans chercher d'email).
     */
    public function testImapConnection(array $config): array
    {
        if (!function_exists('imap_open')) {
            return [
                'success' => false,
                'message' => 'L\'extension PHP IMAP n\'est pas installée sur le serveur.',
                'error_type' => 'extension_missing',
            ];
        }

        try {
            $validation = $this->validateImapConfig($config);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message'],
                    'error_type' => 'validation',
                ];
            }

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

            $connection = @imap_open(
                $mailbox,
                $config['username'],
                $config['password'],
                0,
                1,
                ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
            );

            if (!$connection) {
                $error = imap_last_error();
                return [
                    'success' => false,
                    'message' => $this->parseImapError($error),
                    'error_type' => 'connection',
                    'raw_error' => $error,
                ];
            }

            $check = imap_check($connection);
            $messageCount = $check->Nmsgs ?? 0;
            imap_close($connection);

            return [
                'success' => true,
                'message' => "Connexion IMAP réussie. {$messageCount} message(s) dans la boîte.",
                'message_count' => $messageCount,
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $this->parseImapError($e->getMessage()),
                'error_type' => 'connection',
                'raw_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Valide la configuration SMTP.
     */
    protected function validateSmtpConfig(array $config): array
    {
        if (empty($config['host'])) {
            return ['valid' => false, 'message' => 'Le serveur SMTP n\'est pas configuré'];
        }
        if (empty($config['username'])) {
            return ['valid' => false, 'message' => 'L\'identifiant SMTP n\'est pas configuré'];
        }
        if (empty($config['password'])) {
            return ['valid' => false, 'message' => 'Le mot de passe SMTP n\'est pas configuré'];
        }
        if (empty($config['port'])) {
            return ['valid' => false, 'message' => 'Le port SMTP n\'est pas configuré'];
        }

        return ['valid' => true];
    }

    /**
     * Valide la configuration IMAP.
     */
    protected function validateImapConfig(array $config): array
    {
        if (empty($config['host'])) {
            return ['valid' => false, 'message' => 'Le serveur IMAP n\'est pas configuré'];
        }
        if (empty($config['username'])) {
            return ['valid' => false, 'message' => 'L\'identifiant IMAP n\'est pas configuré'];
        }
        if (empty($config['password'])) {
            return ['valid' => false, 'message' => 'Le mot de passe IMAP n\'est pas configuré'];
        }

        return ['valid' => true];
    }

    /**
     * Parse les erreurs SMTP pour des messages explicites.
     */
    protected function parseSmtpError(\Throwable $e): string
    {
        $message = $e->getMessage();

        // Erreurs de connexion
        if (str_contains($message, 'Connection refused') || str_contains($message, 'Connection timed out')) {
            return 'Impossible de se connecter au serveur SMTP. Vérifiez l\'adresse du serveur et le port.';
        }

        if (str_contains($message, 'getaddrinfo') || str_contains($message, 'could not be resolved')) {
            return 'Adresse du serveur SMTP invalide ou introuvable. Vérifiez le nom du serveur.';
        }

        // Erreurs d'authentification
        if (str_contains($message, 'Authentication failed') || str_contains($message, '535')) {
            return 'Authentification SMTP échouée. Vérifiez l\'identifiant et le mot de passe.';
        }

        if (str_contains($message, 'Username and Password not accepted') || str_contains($message, '534')) {
            return 'Identifiant ou mot de passe incorrect. Pour Gmail, utilisez un mot de passe d\'application.';
        }

        // Erreurs SSL/TLS
        if (str_contains($message, 'SSL') || str_contains($message, 'TLS') || str_contains($message, 'certificate')) {
            return 'Erreur de chiffrement SSL/TLS. Vérifiez le type de chiffrement et le port (SSL=465, TLS=587).';
        }

        // Erreur de port
        if (str_contains($message, 'port')) {
            return 'Erreur de port. Vérifiez que le port correspond au chiffrement (SSL=465, TLS=587, None=25).';
        }

        return 'Erreur SMTP: ' . Str::limit($message, 200);
    }

    /**
     * Parse les erreurs IMAP pour des messages explicites.
     */
    protected function parseImapError(string $error): string
    {
        // Erreurs de connexion
        if (str_contains($error, 'Connection refused') || str_contains($error, 'Connection timed out')) {
            return 'Impossible de se connecter au serveur IMAP. Vérifiez l\'adresse du serveur et le port.';
        }

        if (str_contains($error, 'getaddrinfo') || str_contains($error, 'could not be resolved') || str_contains($error, 'Unknown host')) {
            return 'Adresse du serveur IMAP invalide ou introuvable. Vérifiez le nom du serveur.';
        }

        // Erreurs d'authentification
        if (str_contains($error, 'AUTHENTICATIONFAILED') || str_contains($error, 'Invalid credentials') || str_contains($error, 'authentication failed')) {
            return 'Authentification IMAP échouée. Vérifiez l\'identifiant et le mot de passe.';
        }

        if (str_contains($error, 'LOGIN failed') || str_contains($error, 'NO LOGIN')) {
            return 'Connexion refusée. Vérifiez vos identifiants ou activez l\'accès IMAP dans les paramètres de votre boîte mail.';
        }

        // Erreurs SSL/TLS
        if (str_contains($error, 'SSL') || str_contains($error, 'TLS') || str_contains($error, 'certificate')) {
            return 'Erreur de chiffrement SSL/TLS. Vérifiez le type de chiffrement et le port (SSL=993, TLS=143).';
        }

        // Dossier introuvable
        if (str_contains($error, 'Mailbox') || str_contains($error, 'folder') || str_contains($error, 'INBOX')) {
            return 'Dossier mail introuvable. Vérifiez le nom du dossier (généralement INBOX).';
        }

        // Can't open mailbox
        if (str_contains($error, "Can't open mailbox") || str_contains($error, 'can not open')) {
            return 'Impossible d\'ouvrir la boîte mail. Vérifiez le serveur, le port et les identifiants.';
        }

        return 'Erreur IMAP: ' . Str::limit($error, 200);
    }
}
