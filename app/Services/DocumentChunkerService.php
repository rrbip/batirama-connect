<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentChunkerService
{
    private int $maxChunkSize;
    private int $chunkOverlap;

    public function __construct()
    {
        $this->maxChunkSize = config('documents.chunk_settings.max_chunk_size', 1000);
        $this->chunkOverlap = config('documents.chunk_settings.chunk_overlap', 100);
    }

    /**
     * Découpe un document en chunks
     *
     * @return array<DocumentChunk>
     */
    public function chunk(Document $document): array
    {
        $text = $document->extracted_text;

        if (empty($text)) {
            return [];
        }

        $strategy = $document->chunk_strategy ?? config('documents.chunk_settings.default_strategy', 'paragraph');

        $rawChunks = match ($strategy) {
            'fixed_size' => $this->chunkByFixedSize($text),
            'sentence' => $this->chunkBySentence($text),
            'paragraph' => $this->chunkByParagraph($text),
            'recursive' => $this->chunkRecursive($text),
            default => $this->chunkByParagraph($text),
        };

        // Supprimer les anciens chunks
        $document->chunks()->delete();

        // Créer les nouveaux chunks
        $chunks = [];
        foreach ($rawChunks as $index => $chunkData) {
            $chunk = DocumentChunk::create([
                'document_id' => $document->id,
                'chunk_index' => $index,
                'start_offset' => $chunkData['start_offset'] ?? 0,
                'end_offset' => $chunkData['end_offset'] ?? 0,
                'page_number' => $chunkData['page_number'] ?? null,
                'content' => $chunkData['content'],
                'content_hash' => md5($chunkData['content']),
                'token_count' => $this->estimateTokens($chunkData['content']),
                'context_before' => $chunkData['context_before'] ?? null,
                'context_after' => $chunkData['context_after'] ?? null,
                'metadata' => [
                    'strategy' => $strategy,
                    'document_title' => $document->title ?? $document->original_name,
                    'category' => $document->category,
                ],
                'is_indexed' => false,
                'created_at' => now(),
            ]);

            $chunks[] = $chunk;
        }

        // Mettre à jour le compteur de chunks
        $document->update(['chunk_count' => count($chunks)]);

        return $chunks;
    }

    /**
     * Découpage par taille fixe
     */
    private function chunkByFixedSize(string $text): array
    {
        $chunks = [];
        $textLength = mb_strlen($text);
        $charsPerToken = 4; // Approximation
        $maxChars = $this->maxChunkSize * $charsPerToken;
        $overlapChars = $this->chunkOverlap * $charsPerToken;

        $position = 0;
        while ($position < $textLength) {
            $chunkText = mb_substr($text, $position, $maxChars);

            // Essayer de couper à un espace
            if ($position + $maxChars < $textLength) {
                $lastSpace = mb_strrpos($chunkText, ' ');
                if ($lastSpace !== false && $lastSpace > $maxChars * 0.5) {
                    $chunkText = mb_substr($chunkText, 0, $lastSpace);
                }
            }

            $chunks[] = [
                'content' => trim($chunkText),
                'start_offset' => $position,
                'end_offset' => $position + mb_strlen($chunkText),
            ];

            $position += mb_strlen($chunkText) - $overlapChars;

            // Éviter les boucles infinies
            if ($position <= ($chunks[count($chunks) - 1]['start_offset'] ?? 0)) {
                $position = ($chunks[count($chunks) - 1]['end_offset'] ?? 0);
            }
        }

        return $chunks;
    }

    /**
     * Découpage par phrase
     */
    private function chunkBySentence(string $text): array
    {
        $textTokens = $this->estimateTokens($text);
        $hasNewlines = str_contains($text, "\n");

        Log::info('chunkBySentence: starting', [
            'text_length' => mb_strlen($text),
            'text_tokens' => $textTokens,
            'has_newlines' => $hasNewlines,
            'max_chunk_size' => $this->maxChunkSize,
        ]);

        // Découper en phrases (plusieurs patterns pour plus de robustesse)
        $sentences = preg_split('/(?<=[.!?;:])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        Log::info('chunkBySentence: after sentence split', [
            'sentence_count' => count($sentences),
        ]);

        // Si on n'a qu'une seule phrase mais le texte est conséquent,
        // essayer de découper par lignes
        if (count($sentences) <= 1 && $textTokens > $this->maxChunkSize * 0.5) {
            Log::info('chunkBySentence: falling back to line split');
            $sentences = preg_split('/\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            $sentences = array_map('trim', $sentences);
            $sentences = array_filter($sentences);

            Log::info('chunkBySentence: after line split', [
                'line_count' => count($sentences),
            ]);
        }

        $chunks = $this->groupIntoChunks($sentences);

        Log::info('chunkBySentence: after grouping', [
            'chunk_count' => count($chunks),
        ]);

        // Si on a toujours qu'un seul chunk mais le texte est grand, utiliser fixed_size
        if (count($chunks) === 1 && $textTokens > $this->maxChunkSize) {
            Log::info('chunkBySentence: falling back to fixed_size');
            return $this->chunkByFixedSize($text);
        }

        return $chunks;
    }

    /**
     * Découpage par paragraphe
     */
    private function chunkByParagraph(string $text): array
    {
        $textTokens = $this->estimateTokens($text);
        $hasNewlines = str_contains($text, "\n");
        $hasDoubleNewlines = str_contains($text, "\n\n");

        Log::info('chunkByParagraph: starting', [
            'text_length' => mb_strlen($text),
            'text_tokens' => $textTokens,
            'has_newlines' => $hasNewlines,
            'has_double_newlines' => $hasDoubleNewlines,
            'max_chunk_size' => $this->maxChunkSize,
        ]);

        // Découper en paragraphes (double saut de ligne)
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $paragraphs = array_map('trim', $paragraphs);
        $paragraphs = array_filter($paragraphs);

        Log::info('chunkByParagraph: after paragraph split', [
            'paragraph_count' => count($paragraphs),
        ]);

        // Si on n'a qu'un seul paragraphe mais le texte est conséquent,
        // essayer de découper par lignes simples (typique des PDF)
        if (count($paragraphs) <= 1 && $textTokens > $this->maxChunkSize * 0.5) {
            Log::info('chunkByParagraph: falling back to line split');
            $paragraphs = preg_split('/\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
            $paragraphs = array_map('trim', $paragraphs);
            $paragraphs = array_filter($paragraphs);

            Log::info('chunkByParagraph: after line split', [
                'line_count' => count($paragraphs),
            ]);
        }

        $chunks = $this->groupIntoChunks($paragraphs);

        Log::info('chunkByParagraph: after grouping', [
            'chunk_count' => count($chunks),
        ]);

        // Si on a toujours qu'un seul chunk mais le texte est grand, utiliser fixed_size
        if (count($chunks) === 1 && $textTokens > $this->maxChunkSize) {
            Log::info('chunkByParagraph: falling back to fixed_size');
            return $this->chunkByFixedSize($text);
        }

        return $chunks;
    }

    /**
     * Découpage récursif (le plus intelligent)
     */
    private function chunkRecursive(string $text): array
    {
        $separators = [
            "\n\n",     // Paragraphes
            "\n",       // Lignes
            ". ",       // Phrases
            ", ",       // Clauses
            " ",        // Mots
        ];

        return $this->splitRecursive($text, $separators);
    }

    /**
     * Découpe récursivement avec différents séparateurs
     */
    private function splitRecursive(string $text, array $separators): array
    {
        if (empty($separators)) {
            // Plus de séparateurs, découpage forcé
            return $this->chunkByFixedSize($text);
        }

        $separator = array_shift($separators);
        $parts = explode($separator, $text);

        $chunks = [];
        $currentChunk = '';
        $currentOffset = 0;

        foreach ($parts as $i => $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $testChunk = $currentChunk . ($currentChunk ? $separator : '') . $part;

            if ($this->estimateTokens($testChunk) <= $this->maxChunkSize) {
                $currentChunk = $testChunk;
            } else {
                // Le chunk actuel est trop grand
                if (!empty($currentChunk)) {
                    $chunks[] = [
                        'content' => $currentChunk,
                        'start_offset' => $currentOffset,
                        'end_offset' => $currentOffset + mb_strlen($currentChunk),
                    ];
                    $currentOffset += mb_strlen($currentChunk) + mb_strlen($separator);
                }

                // Si la partie seule est trop grande, la découper récursivement
                if ($this->estimateTokens($part) > $this->maxChunkSize) {
                    $subChunks = $this->splitRecursive($part, $separators);
                    foreach ($subChunks as $subChunk) {
                        $subChunk['start_offset'] += $currentOffset;
                        $subChunk['end_offset'] += $currentOffset;
                        $chunks[] = $subChunk;
                    }
                    $currentOffset += mb_strlen($part) + mb_strlen($separator);
                    $currentChunk = '';
                } else {
                    $currentChunk = $part;
                }
            }
        }

        // Ajouter le dernier chunk
        if (!empty($currentChunk)) {
            $chunks[] = [
                'content' => $currentChunk,
                'start_offset' => $currentOffset,
                'end_offset' => $currentOffset + mb_strlen($currentChunk),
            ];
        }

        return $chunks;
    }

    /**
     * Groupe des éléments en chunks respectant la taille max
     */
    private function groupIntoChunks(array $items): array
    {
        $chunks = [];
        $currentChunk = '';
        $currentOffset = 0;

        foreach ($items as $item) {
            $item = trim($item);
            if (empty($item)) {
                continue;
            }

            $testChunk = $currentChunk . ($currentChunk ? "\n\n" : '') . $item;

            if ($this->estimateTokens($testChunk) <= $this->maxChunkSize) {
                $currentChunk = $testChunk;
            } else {
                // Sauvegarder le chunk actuel
                if (!empty($currentChunk)) {
                    $chunks[] = [
                        'content' => $currentChunk,
                        'start_offset' => $currentOffset,
                        'end_offset' => $currentOffset + mb_strlen($currentChunk),
                    ];
                    $currentOffset += mb_strlen($currentChunk) + 2; // +2 pour \n\n
                }

                // Nouveau chunk
                $currentChunk = $item;

                // Si l'item seul est trop grand, le découper
                if ($this->estimateTokens($item) > $this->maxChunkSize) {
                    $subChunks = $this->chunkByFixedSize($item);
                    foreach ($subChunks as $subChunk) {
                        $subChunk['start_offset'] += $currentOffset;
                        $subChunk['end_offset'] += $currentOffset;
                        $chunks[] = $subChunk;
                    }
                    $currentOffset += mb_strlen($item) + 2;
                    $currentChunk = '';
                }
            }
        }

        // Ajouter le dernier chunk
        if (!empty($currentChunk)) {
            $chunks[] = [
                'content' => $currentChunk,
                'start_offset' => $currentOffset,
                'end_offset' => $currentOffset + mb_strlen($currentChunk),
            ];
        }

        return $chunks;
    }

    /**
     * Estime le nombre de tokens
     */
    private function estimateTokens(string $text): int
    {
        // Approximation: 1 token ≈ 4 caractères
        return (int) ceil(mb_strlen($text) / 4);
    }
}
