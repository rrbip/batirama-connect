<?php

declare(strict_types=1);

namespace App\Services\Support;

class EmailReplyParser
{
    /**
     * Extrait uniquement le nouveau contenu d'un email de réponse.
     * Supprime les citations, signatures, et historique.
     */
    public function extractReply(string $emailBody): string
    {
        // Nettoyer les retours à la ligne Windows
        $emailBody = str_replace("\r\n", "\n", $emailBody);
        $emailBody = str_replace("\r", "\n", $emailBody);

        $lines = explode("\n", $emailBody);
        $replyLines = [];

        foreach ($lines as $line) {
            // Arrêter aux marqueurs de citation courants
            if ($this->isQuoteMarker($line)) {
                break;
            }

            // Ignorer les lignes citées (commençant par >)
            if (str_starts_with(trim($line), '>')) {
                continue;
            }

            // Arrêter à la signature
            if ($this->isSignatureMarker($line)) {
                break;
            }

            $replyLines[] = $line;
        }

        $reply = trim(implode("\n", $replyLines));

        // Nettoyer les lignes vides multiples
        $reply = preg_replace("/\n{3,}/", "\n\n", $reply);

        return $reply;
    }

    /**
     * Détecte les marqueurs de citation email.
     */
    protected function isQuoteMarker(string $line): bool
    {
        $trimmedLine = trim($line);

        $markers = [
            // Anglais
            '/^-{3,}\s*Original Message\s*-{3,}/i',
            '/^-{3,}\s*Forwarded Message\s*-{3,}/i',
            '/^On .+ wrote:$/i',
            '/^On .+, .+ wrote:$/i',
            '/^From:.*Sent:/is',
            '/^From:.*Date:/is',

            // Français
            '/^-{3,}\s*Message original\s*-{3,}/i',
            '/^-{3,}\s*Message transféré\s*-{3,}/i',
            '/^Le \d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}.* a écrit\s*:$/i',
            '/^Le .+ a écrit\s*:$/i',
            '/^De\s*:.*Envoyé\s*:/is',
            '/^De\s*:.*Date\s*:/is',

            // Séparateurs génériques
            '/^_{5,}$/',
            '/^\*{5,}$/',
            '/^={5,}$/',

            // Gmail
            '/^>.*wrote:$/i',
            '/^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2} .* <.*>:$/i',

            // Outlook
            '/^________________________________$/i',
            '/^De :.*$/i',
        ];

        foreach ($markers as $pattern) {
            if (preg_match($pattern, $trimmedLine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Détecte les marqueurs de signature.
     */
    protected function isSignatureMarker(string $line): bool
    {
        $trimmedLine = trim($line);

        $markers = [
            // Standard signature separator
            '/^--\s*$/',
            '/^—\s*$/',

            // Signatures de politesse (français)
            '/^Cordialement\s*,?$/i',
            '/^Bien cordialement\s*,?$/i',
            '/^Sincères salutations\s*,?$/i',
            '/^Meilleures salutations\s*,?$/i',
            '/^Bonne journée\s*,?$/i',
            '/^Bonne réception\s*,?$/i',
            '/^À bientôt\s*,?$/i',
            '/^Merci\s*,?$/i',

            // Signatures de politesse (anglais)
            '/^Best regards\s*,?$/i',
            '/^Kind regards\s*,?$/i',
            '/^Regards\s*,?$/i',
            '/^Thanks\s*,?$/i',
            '/^Thank you\s*,?$/i',
            '/^Sincerely\s*,?$/i',
            '/^Cheers\s*,?$/i',

            // Signatures mobiles
            '/^Envoyé depuis/i',
            '/^Envoyé de mon/i',
            '/^Sent from/i',
            '/^Get Outlook for/i',

            // Signatures génériques
            '/^_{3,}$/',
        ];

        foreach ($markers as $pattern) {
            if (preg_match($pattern, $trimmedLine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrait l'adresse email de l'expéditeur depuis un header.
     */
    public function extractEmailAddress(string $from): ?string
    {
        // Format: "Nom <email@domain.com>" ou "email@domain.com"
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return $matches[1];
        }

        // Email direct
        if (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) {
            return trim($from);
        }

        return null;
    }

    /**
     * Extrait le nom de l'expéditeur depuis un header.
     */
    public function extractSenderName(string $from): ?string
    {
        // Format: "Nom <email@domain.com>"
        if (preg_match('/^([^<]+)</', $from, $matches)) {
            $name = trim($matches[1], ' "\'');
            return $name ?: null;
        }

        return null;
    }

    /**
     * Nettoie le HTML en texte brut.
     */
    public function htmlToText(string $html): string
    {
        // Remplacer les balises de bloc par des retours à la ligne
        $html = preg_replace('/<(br|p|div|tr|li)[^>]*>/i', "\n", $html);

        // Supprimer les balises
        $text = strip_tags($html);

        // Décoder les entités HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Nettoyer les espaces multiples
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Nettoyer les lignes vides multiples
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Parse complet d'un email : extraction du contenu propre.
     */
    public function parse(string $body, bool $isHtml = false): string
    {
        if ($isHtml) {
            $body = $this->htmlToText($body);
        }

        return $this->extractReply($body);
    }
}
