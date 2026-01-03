<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Parse les réponses IA pour détecter leur type (documenté vs suggestion).
 *
 * Utilisé pour le Mode Strict Assisté où l'IA peut proposer des suggestions
 * même sans documentation quand un humain valide la réponse.
 */
class ResponseParser
{
    /**
     * Type de réponse basée sur documentation RAG
     */
    public const TYPE_DOCUMENTED = 'documented';

    /**
     * Type de réponse basée sur connaissances générales (sans documentation)
     */
    public const TYPE_SUGGESTION = 'suggestion';

    /**
     * Type inconnu (pas de marqueur détecté)
     */
    public const TYPE_UNKNOWN = 'unknown';

    /**
     * Pattern pour détecter le marqueur [DOCUMENTED]
     */
    private const DOCUMENTED_PATTERN = '/\s*\[DOCUMENTED\]\s*$/i';

    /**
     * Pattern pour détecter le marqueur [SUGGESTION]
     */
    private const SUGGESTION_PATTERN = '/\s*\[SUGGESTION\]\s*$/i';

    /**
     * Analyse une réponse IA pour détecter son type.
     *
     * @param string $content Contenu de la réponse IA
     * @return array{
     *   type: 'documented'|'suggestion'|'unknown',
     *   content: string,
     *   requires_review: bool,
     *   original_content: string
     * }
     */
    public function parseResponseType(string $content): array
    {
        $originalContent = $content;
        $type = self::TYPE_UNKNOWN;
        $requiresReview = false;

        // Détecter le marqueur [DOCUMENTED]
        if (preg_match(self::DOCUMENTED_PATTERN, $content)) {
            $type = self::TYPE_DOCUMENTED;
            $content = preg_replace(self::DOCUMENTED_PATTERN, '', $content);
        }
        // Détecter le marqueur [SUGGESTION]
        elseif (preg_match(self::SUGGESTION_PATTERN, $content)) {
            $type = self::TYPE_SUGGESTION;
            $requiresReview = true;
            $content = preg_replace(self::SUGGESTION_PATTERN, '', $content);
        }

        return [
            'type' => $type,
            'content' => trim($content),
            'requires_review' => $requiresReview,
            'original_content' => $originalContent,
        ];
    }

    /**
     * Détermine le type de réponse en fonction du contexte RAG disponible.
     *
     * Utilisé quand l'IA n'ajoute pas de marqueur explicite.
     *
     * @param bool $hasContext True si du contexte RAG/learned a été trouvé
     * @param float $maxScore Score maximum des sources trouvées
     * @param float $minScoreThreshold Seuil minimum pour considérer comme documenté
     * @return string Type de réponse (documented, suggestion, unknown)
     */
    public function inferTypeFromContext(
        bool $hasContext,
        float $maxScore = 0.0,
        float $minScoreThreshold = 0.5
    ): string {
        if ($hasContext && $maxScore >= $minScoreThreshold) {
            return self::TYPE_DOCUMENTED;
        }

        if (!$hasContext || $maxScore < $minScoreThreshold) {
            return self::TYPE_SUGGESTION;
        }

        return self::TYPE_UNKNOWN;
    }

    /**
     * Vérifie si une réponse est une suggestion nécessitant une validation.
     */
    public function isSuggestion(string $type): bool
    {
        return $type === self::TYPE_SUGGESTION;
    }

    /**
     * Vérifie si une réponse est documentée.
     */
    public function isDocumented(string $type): bool
    {
        return $type === self::TYPE_DOCUMENTED;
    }

    /**
     * Nettoie les marqueurs de type d'une réponse.
     */
    public function cleanMarkers(string $content): string
    {
        $content = preg_replace(self::DOCUMENTED_PATTERN, '', $content);
        $content = preg_replace(self::SUGGESTION_PATTERN, '', $content);

        return trim($content);
    }
}
