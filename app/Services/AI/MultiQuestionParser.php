<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Str;

/**
 * Parse les réponses multi-questions de l'IA.
 *
 * Détecte et extrait les blocs [QUESTION_BLOCK] pour permettre
 * l'apprentissage granulaire par question/réponse.
 *
 * Format attendu:
 * [QUESTION_BLOCK id="1" question="Question reformulée" type="documented|suggestion"]
 * Contenu de la réponse...
 * [/QUESTION_BLOCK]
 */
class MultiQuestionParser
{
    /**
     * Pattern regex pour détecter les blocs QUESTION_BLOCK avec attribut type
     *
     * Capture:
     * - $1: id (numérique)
     * - $2: question (texte entre guillemets)
     * - $3: type optionnel (documented|suggestion)
     * - $4: contenu du bloc
     */
    private const BLOCK_PATTERN = '/\[QUESTION_BLOCK\s+id="(\d+)"\s+question="([^"]+)"(?:\s+type="(documented|suggestion)")?\](.*?)\[\/QUESTION_BLOCK\]/s';

    /**
     * Pattern alternatif sans attribut type (rétrocompatibilité)
     */
    private const BLOCK_PATTERN_SIMPLE = '/\[QUESTION_BLOCK\s+id="(\d+)"\s+question="([^"]+)"\](.*?)\[\/QUESTION_BLOCK\]/s';

    private ResponseParser $responseParser;

    public function __construct(?ResponseParser $responseParser = null)
    {
        $this->responseParser = $responseParser ?? new ResponseParser();
    }

    /**
     * Parse le contenu d'une réponse IA pour extraire les blocs multi-questions.
     *
     * @param string $content Contenu brut de la réponse IA
     * @return array{
     *   is_multi_question: bool,
     *   blocks: array<array{
     *     id: int,
     *     question: string,
     *     answer: string,
     *     type: string,
     *     is_suggestion: bool,
     *     learned: bool,
     *     learned_at: ?string,
     *     learned_by: ?int
     *   }>,
     *   raw_content: string,
     *   display_content: string,
     *   global_type: ?string,
     *   block_count: int
     * }
     */
    public function parse(string $content): array
    {
        $matches = [];
        preg_match_all(self::BLOCK_PATTERN, $content, $matches, PREG_SET_ORDER);

        // Si aucun match avec le pattern complet, essayer le pattern simple
        if (empty($matches)) {
            preg_match_all(self::BLOCK_PATTERN_SIMPLE, $content, $matches, PREG_SET_ORDER);
        }

        if (empty($matches)) {
            // Pas de blocs multi-questions, vérifier les marqueurs simples
            $parsed = $this->responseParser->parseResponseType($content);

            return [
                'is_multi_question' => false,
                'blocks' => [],
                'raw_content' => $content,
                'display_content' => $parsed['content'],
                'global_type' => $parsed['type'],
                'block_count' => 0,
            ];
        }

        $blocks = [];
        foreach ($matches as $match) {
            $type = $match[3] ?? ResponseParser::TYPE_UNKNOWN;

            // Si le type n'est pas spécifié dans le pattern avec type,
            // vérifier si c'est le pattern simple (sans type)
            if ($type === ResponseParser::TYPE_UNKNOWN && count($match) === 4) {
                // Pattern simple: match[3] est le contenu, pas le type
                $answer = trim($match[3]);
                $type = ResponseParser::TYPE_UNKNOWN;
            } else {
                // Pattern avec type
                $answer = trim($match[4] ?? $match[3] ?? '');
            }

            $blocks[] = [
                'id' => (int) $match[1],
                'question' => trim($match[2]),
                'answer' => $answer,
                'type' => $type,
                'is_suggestion' => $type === ResponseParser::TYPE_SUGGESTION,
                'learned' => false,
                'learned_at' => null,
                'learned_by' => null,
            ];
        }

        // Trier les blocs par ID
        usort($blocks, fn ($a, $b) => $a['id'] <=> $b['id']);

        return [
            'is_multi_question' => count($blocks) > 1,
            'blocks' => $blocks,
            'raw_content' => $content,
            'display_content' => $this->formatForDisplay($blocks),
            'global_type' => null, // Pas de type global, chaque bloc a le sien
            'block_count' => count($blocks),
        ];
    }

    /**
     * Formate les blocs pour l'affichage utilisateur.
     *
     * @param array $blocks Les blocs parsés
     * @return string Contenu formaté pour affichage
     */
    public function formatForDisplay(array $blocks): string
    {
        if (empty($blocks)) {
            return '';
        }

        $parts = [];
        foreach ($blocks as $block) {
            $parts[] = $block['answer'];
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Formate les blocs pour l'affichage client (sans structure de blocs).
     *
     * @param array $blocks Les blocs parsés
     * @return string Contenu formaté pour le client
     */
    public function formatForClient(array $blocks): string
    {
        if (empty($blocks)) {
            return '';
        }

        if (count($blocks) === 1) {
            return $blocks[0]['answer'];
        }

        // Pour plusieurs questions, formater avec numérotation
        $parts = [];
        foreach ($blocks as $index => $block) {
            $num = $index + 1;
            $parts[] = "**{$num}. {$block['question']}**\n\n{$block['answer']}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Vérifie si le contenu contient des blocs multi-questions.
     */
    public function hasMultipleQuestions(string $content): bool
    {
        $matches = [];
        preg_match_all(self::BLOCK_PATTERN, $content, $matches);

        if (count($matches[0]) > 1) {
            return true;
        }

        // Essayer le pattern simple
        preg_match_all(self::BLOCK_PATTERN_SIMPLE, $content, $matches);

        return count($matches[0]) > 1;
    }

    /**
     * Extrait un bloc spécifique par son ID.
     *
     * @param array $blocks Les blocs parsés
     * @param int $blockId L'ID du bloc à extraire
     * @return array|null Le bloc ou null si non trouvé
     */
    public function getBlockById(array $blocks, int $blockId): ?array
    {
        foreach ($blocks as $block) {
            if ($block['id'] === $blockId) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Met à jour le statut d'apprentissage d'un bloc.
     *
     * @param array $blocks Les blocs parsés
     * @param int $blockId L'ID du bloc à mettre à jour
     * @param int $userId L'ID de l'utilisateur qui a validé
     * @return array Les blocs mis à jour
     */
    public function markBlockAsLearned(array $blocks, int $blockId, int $userId): array
    {
        foreach ($blocks as $index => $block) {
            if ($block['id'] === $blockId) {
                $blocks[$index]['learned'] = true;
                $blocks[$index]['learned_at'] = now()->toIso8601String();
                $blocks[$index]['learned_by'] = $userId;
                break;
            }
        }

        return $blocks;
    }

    /**
     * Vérifie si tous les blocs ont été appris.
     */
    public function allBlocksLearned(array $blocks): bool
    {
        if (empty($blocks)) {
            return false;
        }

        foreach ($blocks as $block) {
            if (!($block['learned'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compte le nombre de blocs appris.
     *
     * @return array{learned: int, total: int, percentage: float}
     */
    public function getLearnedStats(array $blocks): array
    {
        $total = count($blocks);
        $learned = 0;

        foreach ($blocks as $block) {
            if ($block['learned'] ?? false) {
                $learned++;
            }
        }

        return [
            'learned' => $learned,
            'total' => $total,
            'percentage' => $total > 0 ? round(($learned / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Compte le nombre de suggestions vs documentés.
     *
     * @return array{documented: int, suggestion: int, unknown: int}
     */
    public function getTypeStats(array $blocks): array
    {
        $stats = [
            'documented' => 0,
            'suggestion' => 0,
            'unknown' => 0,
        ];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? ResponseParser::TYPE_UNKNOWN;
            if (isset($stats[$type])) {
                $stats[$type]++;
            } else {
                $stats['unknown']++;
            }
        }

        return $stats;
    }

    /**
     * Génère les instructions de prompt pour le format multi-questions.
     */
    public function getPromptInstructions(int $maxQuestions = 5): string
    {
        return <<<INSTRUCTIONS

## FORMAT MULTI-QUESTIONS

Si le message de l'utilisateur contient PLUSIEURS questions distinctes :

1. Identifie chaque question (maximum {$maxQuestions})
2. Structure ta réponse avec le format suivant :

```
[QUESTION_BLOCK id="1" question="Question reformulée clairement" type="documented|suggestion"]
Ta réponse complète pour cette question...
[/QUESTION_BLOCK]

[QUESTION_BLOCK id="2" question="Autre question reformulée" type="documented|suggestion"]
Ta réponse complète pour cette autre question...
[/QUESTION_BLOCK]
```

### Règles importantes :
- Chaque bloc est AUTONOME (la réponse doit être complète et utilisable seule)
- Reformule la question pour plus de clarté
- Numérote les IDs séquentiellement (1, 2, 3...)
- Indique `type="documented"` si ta réponse utilise le contexte fourni
- Indique `type="suggestion"` si tu réponds avec tes connaissances générales

Si le message contient une SEULE question, réponds normalement sans utiliser ce format.

INSTRUCTIONS;
    }
}
