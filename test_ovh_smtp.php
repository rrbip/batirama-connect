<?php

/**
 * Script de diagnostic SMTP OVH
 *
 * Usage: php test_ovh_smtp.php
 */

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

// Configuration OVH - À MODIFIER
$config = [
    'host' => 'ssl0.ovh.net',      // ou pro1.mail.ovh.net pour Pro
    'port' => 465,                  // SSL: 465, TLS: 587
    'encryption' => 'ssl',          // ssl ou tls
    'username' => 'VOTRE_EMAIL@DOMAINE.COM',
    'password' => 'VOTRE_MOT_DE_PASSE',
    'to' => 'DESTINATAIRE@EMAIL.COM',
];

echo "=== Test SMTP OVH ===\n\n";

// Test 1: Vérifier que l'extension openssl est chargée
echo "1. Vérification OpenSSL: ";
if (extension_loaded('openssl')) {
    echo "✓ OK\n";
} else {
    echo "✗ MANQUANT - L'extension OpenSSL n'est pas chargée\n";
    exit(1);
}

// Test 2: Vérifier la résolution DNS
echo "2. Résolution DNS ({$config['host']}): ";
$ip = gethostbyname($config['host']);
if ($ip !== $config['host']) {
    echo "✓ OK ({$ip})\n";
} else {
    echo "✗ ÉCHEC - Impossible de résoudre le nom d'hôte\n";
    exit(1);
}

// Test 3: Vérifier que le port est ouvert
echo "3. Connexion TCP ({$config['host']}:{$config['port']}): ";
$socket = @fsockopen($config['host'], $config['port'], $errno, $errstr, 10);
if ($socket) {
    echo "✓ OK\n";
    fclose($socket);
} else {
    echo "✗ ÉCHEC - {$errstr} (code: {$errno})\n";
    echo "   → Le port {$config['port']} est peut-être bloqué par un firewall\n";
    exit(1);
}

// Test 4: Test SMTP avec Symfony Mailer
echo "4. Authentification SMTP: ";

$scheme = ($config['encryption'] === 'ssl' || $config['port'] === 465) ? 'smtps' : 'smtp';

$dsn = sprintf(
    '%s://%s:%s@%s:%d',
    $scheme,
    urlencode($config['username']),
    urlencode($config['password']),
    $config['host'],
    $config['port']
);

echo "\n   DSN: {$scheme}://***:***@{$config['host']}:{$config['port']}\n";

try {
    $transport = Transport::fromDsn($dsn);
    $mailer = new Mailer($transport);

    $email = (new Email())
        ->from($config['username'])
        ->to($config['to'])
        ->subject('[TEST] Diagnostic SMTP OVH - ' . date('Y-m-d H:i:s'))
        ->text("Ceci est un email de test pour vérifier la configuration SMTP OVH.\n\nDate: " . date('Y-m-d H:i:s'));

    $mailer->send($email);

    echo "   ✓ Email envoyé avec succès!\n";
    echo "\n=== SUCCÈS ===\n";
    echo "Vérifiez la boîte de réception de {$config['to']}\n";

} catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
    echo "   ✗ ÉCHEC\n";
    echo "\n=== ERREUR DE TRANSPORT ===\n";
    echo "Message: " . $e->getMessage() . "\n";

    // Analyser l'erreur
    $msg = $e->getMessage();

    if (str_contains($msg, 'Authentication failed') || str_contains($msg, '535')) {
        echo "\n→ DIAGNOSTIC: Authentification échouée\n";
        echo "  - Vérifiez l'adresse email (doit être complète: user@domain.com)\n";
        echo "  - Vérifiez le mot de passe\n";
        echo "  - Pour OVH, le username est l'adresse email complète\n";
    } elseif (str_contains($msg, 'SSL') || str_contains($msg, 'TLS')) {
        echo "\n→ DIAGNOSTIC: Erreur SSL/TLS\n";
        echo "  - Port 465: utilisez SSL (smtps://)\n";
        echo "  - Port 587: utilisez TLS (smtp://)\n";
        echo "  - Essayez de changer le port/chiffrement\n";
    } elseif (str_contains($msg, 'Connection')) {
        echo "\n→ DIAGNOSTIC: Erreur de connexion\n";
        echo "  - Vérifiez que le serveur est accessible\n";
        echo "  - Vérifiez que le port n'est pas bloqué\n";
    }

} catch (\Exception $e) {
    echo "   ✗ ÉCHEC\n";
    echo "\n=== ERREUR INATTENDUE ===\n";
    echo "Type: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
}

echo "\n";
