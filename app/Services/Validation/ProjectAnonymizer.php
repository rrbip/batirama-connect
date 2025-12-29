<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Models\AiSession;
use Illuminate\Support\Facades\Log;

/**
 * Service d'anonymisation des projets pour validation master.
 *
 * Anonymise les données sensibles avant envoi au master:
 * - Informations de l'artisan (nom, entreprise, email)
 * - Informations du particulier (nom, email, téléphone)
 * - Adresses et localisations
 * - IPs et identifiants techniques
 */
class ProjectAnonymizer
{
    /**
     * Patterns pour détecter les données sensibles.
     */
    private const PATTERNS = [
        // Emails
        'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
        // Téléphones français
        'phone' => '/(?:\+33|0)[1-9](?:[\s.-]?\d{2}){4}/',
        // Adresses IP
        'ip' => '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
        // Codes postaux français
        'postal_code' => '/\b\d{5}\b/',
        // Numéro SIRET
        'siret' => '/\b\d{14}\b/',
        // Numéro SIREN
        'siren' => '/\b\d{9}\b/',
    ];

    /**
     * Mots à rechercher et anonymiser (noms propres courants).
     */
    private array $namesToAnonymize = [];

    /**
     * Anonymise une session complète.
     */
    public function anonymize(AiSession $session): array
    {
        // Collecter les noms à anonymiser
        $this->collectNamesToAnonymize($session);

        // Construire le projet anonymisé
        $anonymized = [
            'session_id' => $session->uuid,
            'agent_name' => $session->agent?->name,
            'created_at' => $session->created_at?->toIso8601String(),
            'message_count' => $session->message_count,
            'messages' => $this->anonymizeMessages($session),
            'pre_quote' => $this->anonymizePreQuote($session->pre_quote_data ?? []),
            'files' => $this->anonymizeFiles($session),
        ];

        // Stocker dans la session
        $session->update(['anonymized_project' => $anonymized]);

        Log::info('Session anonymized for master review', [
            'session_id' => $session->uuid,
            'names_anonymized' => count($this->namesToAnonymize),
        ]);

        return $anonymized;
    }

    /**
     * Collecte les noms à anonymiser depuis la session.
     */
    private function collectNamesToAnonymize(AiSession $session): void
    {
        $this->namesToAnonymize = [];

        // Nom de l'artisan
        if ($session->user) {
            $this->addNameToAnonymize($session->user->name, 'Artisan');
            if ($session->editorLink?->artisan_company_name) {
                $this->addNameToAnonymize($session->editorLink->artisan_company_name, 'Entreprise');
            }
        }

        // Nom du particulier
        if ($session->particulier) {
            $this->addNameToAnonymize($session->particulier->name, 'Client');
        }

        // Noms depuis les données du pré-devis
        $preQuote = $session->pre_quote_data ?? [];
        if (!empty($preQuote['client_name'])) {
            $this->addNameToAnonymize($preQuote['client_name'], 'Client');
        }
    }

    /**
     * Ajoute un nom à la liste d'anonymisation.
     */
    private function addNameToAnonymize(string $name, string $replacement): void
    {
        if (strlen($name) < 2) {
            return;
        }

        // Ajouter le nom complet
        $this->namesToAnonymize[mb_strtolower($name)] = "[{$replacement}]";

        // Ajouter les parties du nom (prénom, nom)
        $parts = preg_split('/\s+/', $name);
        foreach ($parts as $index => $part) {
            if (strlen($part) >= 3) {
                $label = $index === 0 ? "{$replacement}_Prénom" : "{$replacement}_Nom";
                $this->namesToAnonymize[mb_strtolower($part)] = "[{$label}]";
            }
        }
    }

    /**
     * Anonymise les messages d'une session.
     */
    private function anonymizeMessages(AiSession $session): array
    {
        $messages = $session->messages()
            ->orderBy('created_at')
            ->get();

        return $messages->map(function ($message) {
            return [
                'role' => $message->role,
                'content' => $this->anonymizeText($message->content),
                'created_at' => $message->created_at?->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Anonymise un pré-devis.
     */
    private function anonymizePreQuote(array $preQuote): array
    {
        if (empty($preQuote)) {
            return [];
        }

        // Copier et anonymiser les champs textuels
        $anonymized = $preQuote;

        // Anonymiser la description
        if (!empty($anonymized['description'])) {
            $anonymized['description'] = $this->anonymizeText($anonymized['description']);
        }

        // Anonymiser les notes
        if (!empty($anonymized['notes'])) {
            $anonymized['notes'] = $this->anonymizeText($anonymized['notes']);
        }

        // Supprimer les champs sensibles
        unset(
            $anonymized['client_name'],
            $anonymized['client_email'],
            $anonymized['client_phone'],
            $anonymized['client_address'],
            $anonymized['artisan_name'],
            $anonymized['artisan_company'],
            $anonymized['artisan_email'],
            $anonymized['artisan_phone'],
            $anonymized['artisan_siret'],
        );

        return $anonymized;
    }

    /**
     * Anonymise les fichiers.
     */
    private function anonymizeFiles(AiSession $session): array
    {
        // Pour les fichiers, on ne retourne que les métadonnées non sensibles
        $files = $session->files ?? [];

        return collect($files)->map(function ($file) {
            return [
                'type' => $file['type'] ?? $file['file_type'] ?? 'unknown',
                'mime_type' => $file['mime_type'] ?? null,
                'size' => $file['size'] ?? $file['size_bytes'] ?? null,
                // L'URL est volontairement omise car elle peut contenir des identifiants
            ];
        })->toArray();
    }

    /**
     * Anonymise un texte en remplaçant les données sensibles.
     */
    public function anonymizeText(string $text): string
    {
        // Remplacer les noms connus
        foreach ($this->namesToAnonymize as $name => $replacement) {
            // Remplacement insensible à la casse
            $text = preg_replace(
                '/\b' . preg_quote($name, '/') . '\b/iu',
                $replacement,
                $text
            );
        }

        // Remplacer les patterns sensibles
        $text = preg_replace(self::PATTERNS['email'], '[EMAIL]', $text);
        $text = preg_replace(self::PATTERNS['phone'], '[TÉLÉPHONE]', $text);
        $text = preg_replace(self::PATTERNS['ip'], '[IP]', $text);
        $text = preg_replace(self::PATTERNS['siret'], '[SIRET]', $text);
        $text = preg_replace(self::PATTERNS['siren'], '[SIREN]', $text);

        // Remplacer les adresses (heuristique simple)
        $text = $this->anonymizeAddresses($text);

        return $text;
    }

    /**
     * Anonymise les adresses (heuristique).
     */
    private function anonymizeAddresses(string $text): string
    {
        // Pattern pour les adresses françaises courantes
        // "123 rue de la Paix" ou "45 bis avenue Victor Hugo"
        $addressPattern = '/\b\d{1,5}(?:\s*(?:bis|ter))?\s+(?:rue|avenue|boulevard|place|impasse|allée|chemin|route|passage)\s+[A-Za-zÀ-ÿ\s\-\']+(?=\s*\d{5}|\s*,|\s*$)/iu';

        $text = preg_replace($addressPattern, '[ADRESSE]', $text);

        // Remplacer les codes postaux isolés (5 chiffres)
        // Mais garder les chiffres dans d'autres contextes
        $text = preg_replace('/\b(\d{5})\s+([A-Za-zÀ-ÿ\-]+)\b/', '[VILLE]', $text);

        return $text;
    }

    /**
     * Vérifie si un texte contient des données sensibles.
     */
    public function containsSensitiveData(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        // Vérifier les noms
        $lowerText = mb_strtolower($text);
        foreach (array_keys($this->namesToAnonymize) as $name) {
            if (str_contains($lowerText, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Génère un rapport d'anonymisation.
     */
    public function getAnonymizationReport(string $originalText, string $anonymizedText): array
    {
        $replacements = [];

        // Compter les remplacements par type
        foreach (self::PATTERNS as $type => $pattern) {
            preg_match_all($pattern, $originalText, $matches);
            if (!empty($matches[0])) {
                $replacements[$type] = count($matches[0]);
            }
        }

        // Compter les noms remplacés
        $namesReplaced = 0;
        foreach (array_keys($this->namesToAnonymize) as $name) {
            $count = substr_count(mb_strtolower($originalText), $name);
            $namesReplaced += $count;
        }

        if ($namesReplaced > 0) {
            $replacements['names'] = $namesReplaced;
        }

        return [
            'original_length' => strlen($originalText),
            'anonymized_length' => strlen($anonymizedText),
            'replacements' => $replacements,
            'total_replacements' => array_sum($replacements),
        ];
    }
}
