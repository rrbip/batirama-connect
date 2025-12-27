<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service de matching SKU pour le marketplace.
 *
 * Fait correspondre les désignations de produits du pré-devis
 * avec les produits du catalogue marketplace.
 *
 * Stratégies de matching:
 * 1. Exact SKU match (100% score)
 * 2. Fuzzy label match (0-100% score based on similarity)
 * 3. Keyword extraction match (based on key terms)
 */
class SkuMatchingService
{
    // Seuils de matching
    public const THRESHOLD_EXACT = 100;
    public const THRESHOLD_HIGH = 80;
    public const THRESHOLD_MEDIUM = 60;
    public const THRESHOLD_LOW = 40;

    private ProductCatalogInterface $catalog;

    public function __construct(ProductCatalogInterface $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * Fait correspondre les items d'un pré-devis avec le catalogue.
     *
     * @param array $preQuoteItems Les items du pré-devis [['designation' => '...', 'quantity' => ..., 'unit' => '...'], ...]
     * @param array $options Options de matching ['threshold' => 60, 'max_results' => 5]
     * @return MatchResult Résultat du matching avec items matchés et non matchés
     */
    public function matchPreQuoteItems(array $preQuoteItems, array $options = []): MatchResult
    {
        $threshold = $options['threshold'] ?? self::THRESHOLD_MEDIUM;
        $maxResults = $options['max_results'] ?? 5;

        $matchedItems = [];
        $unmatchedItems = [];
        $partialMatches = [];

        foreach ($preQuoteItems as $index => $item) {
            $designation = $item['designation'] ?? $item['label'] ?? '';
            $quantity = (float) ($item['quantity'] ?? $item['quantite'] ?? 1);
            $unit = $item['unit'] ?? $item['unite'] ?? 'unité';

            if (empty($designation)) {
                continue;
            }

            // Tenter le matching
            $matches = $this->findMatches($designation, $maxResults);

            if (empty($matches)) {
                $unmatchedItems[] = [
                    'index' => $index,
                    'original' => $item,
                    'designation' => $designation,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'reason' => 'no_products_found',
                ];
                continue;
            }

            $bestMatch = $matches[0];

            if ($bestMatch['score'] >= $threshold) {
                if ($bestMatch['score'] >= self::THRESHOLD_HIGH) {
                    // Match fiable
                    $matchedItems[] = [
                        'index' => $index,
                        'original' => $item,
                        'designation' => $designation,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'product' => $bestMatch['product'],
                        'score' => $bestMatch['score'],
                        'match_type' => $bestMatch['match_type'],
                        'alternatives' => array_slice($matches, 1, 3),
                    ];
                } else {
                    // Match partiel - nécessite validation
                    $partialMatches[] = [
                        'index' => $index,
                        'original' => $item,
                        'designation' => $designation,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'suggestions' => array_slice($matches, 0, 3),
                        'best_score' => $bestMatch['score'],
                    ];
                }
            } else {
                $unmatchedItems[] = [
                    'index' => $index,
                    'original' => $item,
                    'designation' => $designation,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'reason' => 'below_threshold',
                    'best_score' => $bestMatch['score'],
                    'suggestions' => array_slice($matches, 0, 3),
                ];
            }
        }

        // Log si disponible (contexte Laravel)
        try {
            Log::info('SKU matching completed', [
                'total_items' => count($preQuoteItems),
                'matched' => count($matchedItems),
                'partial' => count($partialMatches),
                'unmatched' => count($unmatchedItems),
            ]);
        } catch (\Throwable) {
            // Hors contexte Laravel
        }

        return new MatchResult(
            matched: $matchedItems,
            partial: $partialMatches,
            unmatched: $unmatchedItems,
            stats: [
                'total' => count($preQuoteItems),
                'matched_count' => count($matchedItems),
                'partial_count' => count($partialMatches),
                'unmatched_count' => count($unmatchedItems),
                'match_rate' => count($preQuoteItems) > 0
                    ? round(count($matchedItems) / count($preQuoteItems) * 100, 1)
                    : 0,
            ]
        );
    }

    /**
     * Recherche les produits correspondant à une désignation.
     *
     * @param string $designation La désignation à matcher
     * @param int $limit Nombre max de résultats
     * @return array Liste de matches triés par score décroissant
     */
    public function findMatches(string $designation, int $limit = 5): array
    {
        // 1. Essayer d'abord le match exact SKU (si la désignation contient un code)
        $extractedSku = $this->extractPotentialSku($designation);
        if ($extractedSku) {
            $exactMatch = $this->catalog->findBySku($extractedSku);
            if ($exactMatch) {
                return [[
                    'product' => $exactMatch,
                    'score' => self::THRESHOLD_EXACT,
                    'match_type' => 'exact_sku',
                ]];
            }
        }

        // 2. Recherche par label dans le catalogue
        $searchResults = $this->catalog->searchByLabel($designation, $limit * 2);

        if (empty($searchResults)) {
            return [];
        }

        // 3. Calculer les scores de similarité
        $normalizedDesignation = $this->normalizeText($designation);
        $matches = [];

        foreach ($searchResults as $product) {
            $productLabel = $product['name'] ?? $product['label'] ?? '';
            $normalizedLabel = $this->normalizeText($productLabel);

            // Score combiné: similarité textuelle + correspondance mots-clés
            $textScore = $this->calculateTextSimilarity($normalizedDesignation, $normalizedLabel);
            $keywordScore = $this->calculateKeywordScore($normalizedDesignation, $normalizedLabel);

            // Pondération: 60% texte, 40% mots-clés
            $combinedScore = ($textScore * 0.6) + ($keywordScore * 0.4);

            $matches[] = [
                'product' => $product,
                'score' => round($combinedScore, 1),
                'match_type' => 'fuzzy_label',
                'text_score' => round($textScore, 1),
                'keyword_score' => round($keywordScore, 1),
            ];
        }

        // Trier par score décroissant
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * Extrait un potentiel code SKU de la désignation.
     */
    private function extractPotentialSku(string $designation): ?string
    {
        // Patterns de codes produits courants
        $patterns = [
            '/\b([A-Z]{2,4}[-_]?\d{4,8})\b/i',     // XX-12345 ou XX12345
            '/\bREF[:\s]*([A-Z0-9-]+)\b/i',         // REF: ABC123
            '/\bSKU[:\s]*([A-Z0-9-]+)\b/i',         // SKU: ABC123
            '/\b(\d{8,13})\b/',                     // EAN/GTIN
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $designation, $matches)) {
                return strtoupper($matches[1]);
            }
        }

        return null;
    }

    /**
     * Normalise un texte pour la comparaison.
     */
    private function normalizeText(string $text): string
    {
        // Minuscules
        $text = mb_strtolower($text);

        // Supprimer accents
        $text = $this->removeAccents($text);

        // Supprimer ponctuation sauf tirets
        $text = preg_replace('/[^\w\s-]/u', ' ', $text);

        // Normaliser espaces
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }

    /**
     * Supprime les accents d'un texte.
     */
    private function removeAccents(string $text): string
    {
        $accents = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'é' => 'e',
            'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        return strtr($text, $accents);
    }

    /**
     * Calcule la similarité textuelle entre deux chaînes.
     */
    private function calculateTextSimilarity(string $str1, string $str2): float
    {
        if ($str1 === $str2) {
            return 100.0;
        }

        if (empty($str1) || empty($str2)) {
            return 0.0;
        }

        // Utiliser similar_text pour un pourcentage de similarité
        similar_text($str1, $str2, $percent);

        return $percent;
    }

    /**
     * Calcule un score basé sur les mots-clés communs.
     */
    private function calculateKeywordScore(string $text1, string $text2): float
    {
        $words1 = array_filter(explode(' ', $text1), fn($w) => strlen($w) > 2);
        $words2 = array_filter(explode(' ', $text2), fn($w) => strlen($w) > 2);

        if (empty($words1) || empty($words2)) {
            return 0.0;
        }

        // Mots-clés techniques importants (pondération x2)
        $technicalTerms = [
            'peinture', 'enduit', 'placo', 'platre', 'bande', 'joint',
            'primaire', 'impression', 'finition', 'acrylique', 'glycero',
            'satine', 'mat', 'brillant', 'blanc', 'couleur', 'teinte',
            'seau', 'pot', 'bidon', 'rouleau', 'pinceau', 'brosse',
            'interieur', 'exterieur', 'facade', 'plafond', 'mur', 'sol',
        ];

        $commonWords = array_intersect($words1, $words2);
        $totalWords = count(array_unique(array_merge($words1, $words2)));

        if ($totalWords === 0) {
            return 0.0;
        }

        // Score de base: pourcentage de mots communs
        $baseScore = count($commonWords) / $totalWords * 100;

        // Bonus pour mots techniques matchés
        $technicalMatches = array_intersect($commonWords, $technicalTerms);
        $technicalBonus = count($technicalMatches) * 5;

        return min(100, $baseScore + $technicalBonus);
    }

    /**
     * Crée les MarketplaceOrderItems à partir du résultat de matching.
     *
     * @param int $orderId ID de la commande marketplace
     * @param MatchResult $matchResult Résultat du matching
     * @return Collection Collection de MarketplaceOrderItem créés
     */
    public function createOrderItems(int $orderId, MatchResult $matchResult): Collection
    {
        $items = collect();

        // Items matchés
        foreach ($matchResult->matched as $match) {
            $product = $match['product'];
            $items->push(MarketplaceOrderItem::create([
                'order_id' => $orderId,
                'original_designation' => $match['designation'],
                'product_id' => $product['id'] ?? null,
                'product_sku' => $product['sku'] ?? null,
                'product_name' => $product['name'] ?? $product['label'] ?? null,
                'match_status' => $match['score'] >= self::THRESHOLD_HIGH
                    ? MarketplaceOrderItem::MATCH_STATUS_MATCHED
                    : MarketplaceOrderItem::MATCH_STATUS_PARTIAL,
                'match_score' => $match['score'],
                'quantity' => $match['quantity'],
                'unit' => $match['unit'],
                'unit_price_ht' => $product['price_ht'] ?? $product['unit_price'] ?? null,
                'line_status' => MarketplaceOrderItem::LINE_STATUS_INCLUDED,
                'metadata' => [
                    'original_item' => $match['original'],
                    'match_type' => $match['match_type'],
                    'alternatives' => $match['alternatives'] ?? [],
                ],
            ]));
        }

        // Items partiellement matchés
        foreach ($matchResult->partial as $partial) {
            $items->push(MarketplaceOrderItem::create([
                'order_id' => $orderId,
                'original_designation' => $partial['designation'],
                'match_status' => MarketplaceOrderItem::MATCH_STATUS_PARTIAL,
                'match_score' => $partial['best_score'],
                'quantity' => $partial['quantity'],
                'unit' => $partial['unit'],
                'line_status' => MarketplaceOrderItem::LINE_STATUS_PENDING,
                'metadata' => [
                    'original_item' => $partial['original'],
                    'suggestions' => $partial['suggestions'],
                ],
            ]));
        }

        // Items non matchés
        foreach ($matchResult->unmatched as $unmatched) {
            $items->push(MarketplaceOrderItem::create([
                'order_id' => $orderId,
                'original_designation' => $unmatched['designation'],
                'match_status' => MarketplaceOrderItem::MATCH_STATUS_NOT_FOUND,
                'quantity' => $unmatched['quantity'],
                'unit' => $unmatched['unit'],
                'line_status' => MarketplaceOrderItem::LINE_STATUS_PENDING,
                'metadata' => [
                    'original_item' => $unmatched['original'],
                    'reason' => $unmatched['reason'],
                    'suggestions' => $unmatched['suggestions'] ?? [],
                ],
            ]));
        }

        return $items;
    }
}
