<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\IndexingMethod;

/**
 * Service pour gérer les stratégies d'indexation et la détection de réponse directe Q/R.
 */
class IndexingStrategyService
{
    /**
     * Seuil de score pour réponse directe Q/R (sans appel LLM).
     */
    public const DIRECT_QR_THRESHOLD = 0.95;

    /**
     * Détermine si un résultat est une Q/R directe utilisable.
     *
     * @param array $result Résultat de recherche Qdrant
     * @param float|null $threshold Seuil de score (défaut: DIRECT_QR_THRESHOLD)
     */
    public function isDirectQrResult(array $result, ?float $threshold = null): bool
    {
        $payload = $result['payload'] ?? [];
        $score = $result['score'] ?? 0;
        $threshold = $threshold ?? self::DIRECT_QR_THRESHOLD;

        return ($payload['type'] ?? '') === 'qa_pair'
            && $score >= $threshold
            && !empty($payload['display_text']);
    }

    /**
     * Extrait la réponse directe d'un résultat Q/R.
     *
     * @param array $result Résultat de recherche Qdrant
     * @return array Données de la réponse directe
     */
    public function extractDirectAnswer(array $result): array
    {
        $payload = $result['payload'] ?? [];

        return [
            'question' => $payload['question'] ?? '',
            'answer' => $payload['display_text'] ?? '',
            'source' => $payload['source_doc'] ?? '',
            'context' => $payload['parent_context'] ?? '',
            'category' => $payload['category'] ?? '',
            'score' => $result['score'] ?? 0,
            'is_faq' => $payload['is_faq'] ?? false,
        ];
    }

    /**
     * Construit le filtre Qdrant pour les catégories.
     *
     * @param array $categoryNames Liste des noms de catégories
     * @return array Filtre Qdrant
     */
    public function buildCategoryFilter(array $categoryNames): array
    {
        if (empty($categoryNames)) {
            return [];
        }

        $conditions = array_map(fn ($name) => [
            'key' => 'category',
            'match' => ['value' => $name],
        ], $categoryNames);

        return ['should' => $conditions];
    }

    /**
     * Retourne les champs du payload selon la méthode d'indexation.
     *
     * @param IndexingMethod $method Méthode d'indexation
     * @return array Configuration des champs
     */
    public function getPayloadConfig(IndexingMethod $method): array
    {
        return match ($method) {
            IndexingMethod::QR_ATOMIQUE => [
                'content_field' => 'display_text',
                'category_field' => 'category',
                'source_field' => 'source_doc',
                'context_field' => 'parent_context',
                'question_field' => 'question',
                'type_field' => 'type',
                'types' => ['qa_pair', 'source_material'],
            ],
        };
    }

    /**
     * Extrait le contenu d'un résultat selon la méthode d'indexation.
     *
     * @param array $result Résultat de recherche Qdrant
     * @param IndexingMethod $method Méthode d'indexation
     * @return string Contenu extrait
     */
    public function extractContent(array $result, IndexingMethod $method): string
    {
        $payload = $result['payload'] ?? [];
        $config = $this->getPayloadConfig($method);

        return $payload[$config['content_field']] ?? '';
    }

    /**
     * Extrait la source d'un résultat selon la méthode d'indexation.
     *
     * @param array $result Résultat de recherche Qdrant
     * @param IndexingMethod $method Méthode d'indexation
     * @return string Source extraite
     */
    public function extractSource(array $result, IndexingMethod $method): string
    {
        $payload = $result['payload'] ?? [];
        $config = $this->getPayloadConfig($method);

        return $payload[$config['source_field']] ?? 'Document';
    }

    /**
     * Extrait la catégorie d'un résultat selon la méthode d'indexation.
     *
     * @param array $result Résultat de recherche Qdrant
     * @param IndexingMethod $method Méthode d'indexation
     * @return string Catégorie extraite
     */
    public function extractCategory(array $result, IndexingMethod $method): string
    {
        $payload = $result['payload'] ?? [];
        $config = $this->getPayloadConfig($method);

        return $payload[$config['category_field']] ?? '';
    }
}
