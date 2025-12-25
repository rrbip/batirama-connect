<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helper class for text manipulation and encoding fixes
 */
class TextHelper
{
    /**
     * Corrige les problèmes d'encodage UTF-8 (double encodage ou mauvaise détection)
     *
     * Gère les cas où le texte UTF-8 a été interprété comme ISO-8859-1 puis ré-encodé
     * Ex: "Ã©" au lieu de "é", "garanï¿½e" au lieu de "garantie"
     */
    public static function fixUtf8Encoding(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        // Détecte si le texte est déjà en UTF-8 valide
        if (mb_check_encoding($text, 'UTF-8')) {
            // Vérifie s'il y a des séquences de double encodage UTF-8
            // Ex: "Ã©" au lieu de "é" (UTF-8 interprété comme ISO-8859-1 puis ré-encodé)
            $decoded = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $text);
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                // C'était du double encodage, on utilise la version décodée
                return $decoded;
            }
        }

        // Essaye de convertir depuis ISO-8859-1 si ce n'est pas de l'UTF-8 valide
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            if ($converted !== false) {
                return $converted;
            }
        }

        // Supprime les caractères invalides en dernier recours
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
