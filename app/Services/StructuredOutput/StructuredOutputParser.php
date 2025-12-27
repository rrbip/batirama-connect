<?php

declare(strict_types=1);

namespace App\Services\StructuredOutput;

use Illuminate\Support\Facades\Log;

/**
 * Parser pour extraire et valider les outputs structurés des réponses IA.
 *
 * Les outputs structurés sont des blocs JSON encadrés par des balises spécifiques
 * dans les réponses de l'assistant (ex: ```json-quote ... ```).
 */
class StructuredOutputParser
{
    /**
     * Patterns pour détecter les blocs JSON structurés.
     */
    private const PATTERNS = [
        // ```json-quote ... ```
        '/```json-quote\s*\n?(.*?)\n?```/s',
        // ```json-project ... ```
        '/```json-project\s*\n?(.*?)\n?```/s',
        // ```json-pre-quote ... ```
        '/```json-pre-quote\s*\n?(.*?)\n?```/s',
        // ```json ... ``` (fallback générique)
        '/```json\s*\n?(.*?)\n?```/s',
    ];

    /**
     * Types d'output structuré supportés.
     */
    public const TYPE_PRE_QUOTE = 'pre_quote';
    public const TYPE_PROJECT = 'project';
    public const TYPE_QUOTE = 'quote';

    /**
     * Parse le contenu pour extraire les outputs structurés.
     *
     * @param string $content Le contenu de la réponse IA
     * @return array|null Les données structurées ou null si non trouvées
     */
    public function parse(string $content): ?array
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $jsonString = trim($matches[1]);

                try {
                    $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

                    // Déterminer le type basé sur le pattern
                    $type = $this->determineType($pattern, $data);

                    return [
                        'type' => $type,
                        'data' => $data,
                        'raw' => $jsonString,
                    ];
                } catch (\JsonException $e) {
                    Log::warning('StructuredOutputParser: Invalid JSON', [
                        'pattern' => $pattern,
                        'json' => substr($jsonString, 0, 200),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Parse et valide un pré-devis structuré.
     *
     * @param string $content Le contenu de la réponse IA
     * @return array|null Le pré-devis validé ou null
     */
    public function parsePreQuote(string $content): ?array
    {
        $result = $this->parse($content);

        if (!$result) {
            return null;
        }

        // Valider la structure du pré-devis
        $preQuote = $this->validatePreQuoteSchema($result['data']);

        if (!$preQuote) {
            return null;
        }

        return [
            'type' => self::TYPE_PRE_QUOTE,
            'data' => $preQuote,
            'raw' => $result['raw'],
        ];
    }

    /**
     * Extrait tous les outputs structurés d'un contenu.
     *
     * @param string $content Le contenu de la réponse IA
     * @return array Liste de tous les outputs trouvés
     */
    public function parseAll(string $content): array
    {
        $results = [];

        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $jsonString = trim($match[1]);

                    try {
                        $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
                        $type = $this->determineType($pattern, $data);

                        $results[] = [
                            'type' => $type,
                            'data' => $data,
                            'raw' => $jsonString,
                        ];
                    } catch (\JsonException $e) {
                        // Skip invalid JSON
                        continue;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Détermine le type d'output basé sur le pattern et les données.
     */
    private function determineType(string $pattern, array $data): string
    {
        // D'abord vérifier le pattern
        if (str_contains($pattern, 'json-quote')) {
            return self::TYPE_QUOTE;
        }
        if (str_contains($pattern, 'json-pre-quote')) {
            return self::TYPE_PRE_QUOTE;
        }
        if (str_contains($pattern, 'json-project')) {
            return self::TYPE_PROJECT;
        }

        // Ensuite inférer depuis les données
        if (isset($data['items']) && isset($data['total_ht'])) {
            return self::TYPE_PRE_QUOTE;
        }
        if (isset($data['project_type']) || isset($data['description'])) {
            return self::TYPE_PROJECT;
        }

        return 'unknown';
    }

    /**
     * Valide et normalise un schéma de pré-devis.
     *
     * Structure attendue:
     * {
     *   "project_type": "peinture|plomberie|electricite|...",
     *   "description": "Description du projet",
     *   "surface_m2": 50,
     *   "items": [
     *     {
     *       "designation": "Préparation des surfaces",
     *       "quantity": 1,
     *       "unit": "forfait",
     *       "unit_price_ht": 200.00,
     *       "total_ht": 200.00
     *     }
     *   ],
     *   "total_ht": 1500.00,
     *   "tva_rate": 10,
     *   "total_ttc": 1650.00,
     *   "duration_days": 3,
     *   "notes": "Conditions particulières..."
     * }
     */
    private function validatePreQuoteSchema(array $data): ?array
    {
        // Champs obligatoires
        if (!isset($data['items']) || !is_array($data['items'])) {
            Log::debug('PreQuote validation: missing items');
            return null;
        }

        if (empty($data['items'])) {
            Log::debug('PreQuote validation: empty items');
            return null;
        }

        // Normaliser les items
        $normalizedItems = [];
        $calculatedTotal = 0;

        foreach ($data['items'] as $index => $item) {
            // Vérifier les champs obligatoires de l'item
            if (!isset($item['designation']) || empty($item['designation'])) {
                Log::debug("PreQuote validation: item $index missing designation");
                continue;
            }

            $quantity = $this->normalizeNumber($item['quantity'] ?? 1);
            $unitPrice = $this->normalizeNumber($item['unit_price_ht'] ?? $item['prix_unitaire_ht'] ?? 0);
            $itemTotal = $this->normalizeNumber($item['total_ht'] ?? ($quantity * $unitPrice));

            $normalizedItems[] = [
                'designation' => trim($item['designation']),
                'quantity' => $quantity,
                'unit' => $item['unit'] ?? $item['unite'] ?? 'unité',
                'unit_price_ht' => round($unitPrice, 2),
                'total_ht' => round($itemTotal, 2),
            ];

            $calculatedTotal += $itemTotal;
        }

        if (empty($normalizedItems)) {
            Log::debug('PreQuote validation: no valid items after normalization');
            return null;
        }

        // Calculer les totaux
        $totalHt = $this->normalizeNumber($data['total_ht'] ?? $calculatedTotal);
        $tvaRate = $this->normalizeNumber($data['tva_rate'] ?? $data['taux_tva'] ?? 10);
        $totalTtc = $this->normalizeNumber($data['total_ttc'] ?? ($totalHt * (1 + $tvaRate / 100)));

        return [
            'project_type' => $data['project_type'] ?? $data['type_projet'] ?? null,
            'description' => $data['description'] ?? null,
            'surface_m2' => $this->normalizeNumber($data['surface_m2'] ?? $data['surface'] ?? null),
            'items' => $normalizedItems,
            'total_ht' => round($totalHt, 2),
            'tva_rate' => $tvaRate,
            'total_ttc' => round($totalTtc, 2),
            'duration_days' => $this->normalizeNumber($data['duration_days'] ?? $data['duree_jours'] ?? null),
            'notes' => $data['notes'] ?? $data['remarques'] ?? null,
        ];
    }

    /**
     * Normalise une valeur numérique.
     */
    private function normalizeNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            // Remplacer la virgule par un point
            $value = str_replace(',', '.', $value);
            // Supprimer les espaces et symboles monétaires
            $value = preg_replace('/[^\d.]/', '', $value);

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Retire le bloc structuré du contenu pour obtenir le texte seul.
     *
     * @param string $content Le contenu avec le bloc JSON
     * @return string Le contenu sans le bloc JSON
     */
    public function stripStructuredOutput(string $content): string
    {
        foreach (self::PATTERNS as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }

        // Nettoyer les lignes vides multiples
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return trim($content);
    }

    /**
     * Génère le template d'instructions pour le prompt système.
     *
     * @param string $type Le type d'output attendu
     * @return string Les instructions formatées
     */
    public function getPromptInstructions(string $type = self::TYPE_PRE_QUOTE): string
    {
        return match ($type) {
            self::TYPE_PRE_QUOTE => $this->getPreQuotePromptInstructions(),
            self::TYPE_PROJECT => $this->getProjectPromptInstructions(),
            default => '',
        };
    }

    /**
     * Instructions prompt pour pré-devis.
     */
    private function getPreQuotePromptInstructions(): string
    {
        return <<<'PROMPT'

## Format de Pré-Devis Structuré

Lorsque tu as suffisamment d'informations pour estimer un projet, génère un pré-devis au format JSON structuré.
Place le JSON dans un bloc de code avec le tag `json-pre-quote`.

Exemple de format:
```json-pre-quote
{
  "project_type": "peinture",
  "description": "Peinture des murs et plafonds du salon",
  "surface_m2": 45,
  "items": [
    {
      "designation": "Préparation des surfaces (lessivage, rebouchage)",
      "quantity": 45,
      "unit": "m²",
      "unit_price_ht": 8.00,
      "total_ht": 360.00
    },
    {
      "designation": "Application peinture acrylique mate (2 couches)",
      "quantity": 45,
      "unit": "m²",
      "unit_price_ht": 18.00,
      "total_ht": 810.00
    },
    {
      "designation": "Protection du mobilier et nettoyage",
      "quantity": 1,
      "unit": "forfait",
      "unit_price_ht": 150.00,
      "total_ht": 150.00
    }
  ],
  "total_ht": 1320.00,
  "tva_rate": 10,
  "total_ttc": 1452.00,
  "duration_days": 2,
  "notes": "Devis indicatif, une visite sur place est recommandée pour confirmer les surfaces."
}
```

Important:
- Génère le pré-devis seulement quand tu as assez d'informations (type de travaux, surface approximative)
- Les prix doivent être réalistes pour le marché français
- Utilise la TVA à 10% pour les travaux de rénovation (logement > 2 ans)
- Inclus toujours les postes de préparation et finition

PROMPT;
    }

    /**
     * Instructions prompt pour projet.
     */
    private function getProjectPromptInstructions(): string
    {
        return <<<'PROMPT'

## Format de Projet Structuré

Pour résumer un projet discuté, utilise le format JSON structuré dans un bloc `json-project`.

Exemple:
```json-project
{
  "project_type": "renovation_salle_de_bain",
  "description": "Rénovation complète de la salle de bain",
  "requirements": [
    "Remplacement de la baignoire par une douche à l'italienne",
    "Nouveau carrelage sol et murs",
    "Changement du meuble vasque"
  ],
  "constraints": [
    "Budget maximum: 8000€",
    "Délai souhaité: avant fin mars"
  ],
  "surface_m2": 6
}
```

PROMPT;
    }
}
