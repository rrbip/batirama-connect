<?php

declare(strict_types=1);

namespace App\Services\StructuredOutput;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Schéma et validation pour les pré-devis structurés.
 */
class PreQuoteSchema
{
    /**
     * Types de projets reconnus.
     */
    public const PROJECT_TYPES = [
        'peinture' => 'Peinture',
        'plomberie' => 'Plomberie',
        'electricite' => 'Électricité',
        'maconnerie' => 'Maçonnerie',
        'carrelage' => 'Carrelage',
        'menuiserie' => 'Menuiserie',
        'isolation' => 'Isolation',
        'toiture' => 'Toiture',
        'chauffage' => 'Chauffage',
        'climatisation' => 'Climatisation',
        'renovation_salle_de_bain' => 'Rénovation salle de bain',
        'renovation_cuisine' => 'Rénovation cuisine',
        'renovation_complete' => 'Rénovation complète',
        'extension' => 'Extension',
        'amenagement_combles' => 'Aménagement combles',
        'terrassement' => 'Terrassement',
        'facade' => 'Façade',
        'autre' => 'Autre',
    ];

    /**
     * Unités de mesure acceptées.
     */
    public const UNITS = [
        'm²' => 'Mètre carré',
        'm' => 'Mètre linéaire',
        'ml' => 'Mètre linéaire',
        'u' => 'Unité',
        'unité' => 'Unité',
        'forfait' => 'Forfait',
        'h' => 'Heure',
        'jour' => 'Jour',
        'lot' => 'Lot',
        'ens' => 'Ensemble',
        'kg' => 'Kilogramme',
        'l' => 'Litre',
    ];

    /**
     * Taux de TVA applicables.
     */
    public const TVA_RATES = [
        5.5 => 'Travaux d\'amélioration énergétique',
        10 => 'Travaux de rénovation (logement > 2 ans)',
        20 => 'Taux normal (neuf, gros œuvre)',
    ];

    /**
     * Règles de validation pour un pré-devis.
     */
    public static function rules(): array
    {
        return [
            'project_type' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'surface_m2' => 'nullable|numeric|min:0|max:100000',
            'items' => 'required|array|min:1|max:100',
            'items.*.designation' => 'required|string|min:3|max:500',
            'items.*.quantity' => 'required|numeric|min:0.01|max:100000',
            'items.*.unit' => 'required|string|max:20',
            'items.*.unit_price_ht' => 'required|numeric|min:0|max:1000000',
            'items.*.total_ht' => 'required|numeric|min:0|max:10000000',
            'total_ht' => 'required|numeric|min:0|max:100000000',
            'tva_rate' => 'required|numeric|in:5.5,10,20',
            'total_ttc' => 'required|numeric|min:0|max:120000000',
            'duration_days' => 'nullable|integer|min:1|max:365',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Messages d'erreur personnalisés.
     */
    public static function messages(): array
    {
        return [
            'items.required' => 'Le pré-devis doit contenir au moins une ligne.',
            'items.min' => 'Le pré-devis doit contenir au moins une ligne.',
            'items.*.designation.required' => 'Chaque ligne doit avoir une désignation.',
            'items.*.quantity.required' => 'Chaque ligne doit avoir une quantité.',
            'items.*.unit_price_ht.required' => 'Chaque ligne doit avoir un prix unitaire.',
            'total_ht.required' => 'Le total HT est obligatoire.',
            'tva_rate.in' => 'Le taux de TVA doit être 5.5%, 10% ou 20%.',
        ];
    }

    /**
     * Valide un pré-devis.
     *
     * @param array $data Les données du pré-devis
     * @return array Les données validées et nettoyées
     * @throws ValidationException Si la validation échoue
     */
    public static function validate(array $data): array
    {
        $validator = Validator::make($data, self::rules(), self::messages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return self::sanitize($validator->validated());
    }

    /**
     * Valide sans lever d'exception.
     *
     * @param array $data Les données du pré-devis
     * @return array|null Les données validées ou null si invalide
     */
    public static function validateSafe(array $data): ?array
    {
        try {
            return self::validate($data);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Nettoie et normalise les données.
     */
    public static function sanitize(array $data): array
    {
        // Normaliser le type de projet
        if (isset($data['project_type'])) {
            $data['project_type'] = self::normalizeProjectType($data['project_type']);
        }

        // Normaliser les unités
        if (isset($data['items'])) {
            foreach ($data['items'] as &$item) {
                $item['unit'] = self::normalizeUnit($item['unit']);
                $item['designation'] = trim($item['designation']);
            }
        }

        // Recalculer le total si nécessaire
        $calculatedTotal = array_sum(array_column($data['items'] ?? [], 'total_ht'));
        if (abs($calculatedTotal - ($data['total_ht'] ?? 0)) > 0.01) {
            $data['total_ht'] = round($calculatedTotal, 2);
            $data['total_ttc'] = round($data['total_ht'] * (1 + ($data['tva_rate'] ?? 10) / 100), 2);
        }

        return $data;
    }

    /**
     * Normalise un type de projet.
     */
    private static function normalizeProjectType(string $type): string
    {
        $type = mb_strtolower(trim($type));
        $type = str_replace(['é', 'è', 'ê'], 'e', $type);
        $type = str_replace(['à', 'â'], 'a', $type);
        $type = str_replace(' ', '_', $type);
        $type = preg_replace('/[^a-z_]/', '', $type);

        // Mapper vers un type connu
        $mapping = [
            'peinture' => 'peinture',
            'plomberie' => 'plomberie',
            'electricite' => 'electricite',
            'electrique' => 'electricite',
            'maconnerie' => 'maconnerie',
            'macon' => 'maconnerie',
            'carrelage' => 'carrelage',
            'faience' => 'carrelage',
            'menuiserie' => 'menuiserie',
            'bois' => 'menuiserie',
            'isolation' => 'isolation',
            'toiture' => 'toiture',
            'toit' => 'toiture',
            'chauffage' => 'chauffage',
            'clim' => 'climatisation',
            'climatisation' => 'climatisation',
            'salle_de_bain' => 'renovation_salle_de_bain',
            'sdb' => 'renovation_salle_de_bain',
            'cuisine' => 'renovation_cuisine',
            'renovation' => 'renovation_complete',
            'extension' => 'extension',
            'agrandissement' => 'extension',
            'combles' => 'amenagement_combles',
            'terrassement' => 'terrassement',
            'facade' => 'facade',
            'ravalement' => 'facade',
        ];

        return $mapping[$type] ?? 'autre';
    }

    /**
     * Normalise une unité de mesure.
     */
    private static function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));

        $mapping = [
            'm2' => 'm²',
            'mètre carré' => 'm²',
            'mètres carrés' => 'm²',
            'metre carre' => 'm²',
            'ml' => 'ml',
            'mètre linéaire' => 'ml',
            'm lineaire' => 'ml',
            'mètre' => 'm',
            'unité' => 'u',
            'unite' => 'u',
            'pièce' => 'u',
            'piece' => 'u',
            'heure' => 'h',
            'heures' => 'h',
            'jour' => 'jour',
            'jours' => 'jour',
            'journée' => 'jour',
            'ensemble' => 'ens',
            'kilogramme' => 'kg',
            'kilo' => 'kg',
            'litre' => 'l',
            'litres' => 'l',
        ];

        return $mapping[$unit] ?? $unit;
    }

    /**
     * Génère un résumé textuel du pré-devis.
     */
    public static function toSummary(array $data): string
    {
        $lines = [];

        if (!empty($data['project_type'])) {
            $typeName = self::PROJECT_TYPES[$data['project_type']] ?? $data['project_type'];
            $lines[] = "Type de projet: {$typeName}";
        }

        if (!empty($data['description'])) {
            $lines[] = "Description: {$data['description']}";
        }

        if (!empty($data['surface_m2'])) {
            $lines[] = "Surface: {$data['surface_m2']} m²";
        }

        $lines[] = '';
        $lines[] = 'Lignes du devis:';

        foreach ($data['items'] ?? [] as $item) {
            $lines[] = sprintf(
                '- %s: %s %s × %.2f € = %.2f € HT',
                $item['designation'],
                $item['quantity'],
                $item['unit'],
                $item['unit_price_ht'],
                $item['total_ht']
            );
        }

        $lines[] = '';
        $lines[] = sprintf('Total HT: %.2f €', $data['total_ht'] ?? 0);
        $lines[] = sprintf('TVA (%.1f%%): %.2f €', $data['tva_rate'] ?? 10, ($data['total_ttc'] ?? 0) - ($data['total_ht'] ?? 0));
        $lines[] = sprintf('Total TTC: %.2f €', $data['total_ttc'] ?? 0);

        if (!empty($data['duration_days'])) {
            $lines[] = sprintf('Durée estimée: %d jour(s)', $data['duration_days']);
        }

        if (!empty($data['notes'])) {
            $lines[] = '';
            $lines[] = "Notes: {$data['notes']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Convertit un pré-devis en format pour webhook.
     */
    public static function toWebhookPayload(array $data): array
    {
        return [
            'pre_quote' => [
                'project_type' => $data['project_type'] ?? null,
                'description' => $data['description'] ?? null,
                'surface_m2' => $data['surface_m2'] ?? null,
                'items_count' => count($data['items'] ?? []),
                'items' => $data['items'] ?? [],
                'total_ht' => $data['total_ht'] ?? 0,
                'tva_rate' => $data['tva_rate'] ?? 10,
                'total_ttc' => $data['total_ttc'] ?? 0,
                'duration_days' => $data['duration_days'] ?? null,
            ],
        ];
    }
}
