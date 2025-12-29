<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Log;

class DocumentChunkerService
{
    private int $maxChunkSize;
    private int $chunkOverlap;

    public function __construct()
    {
        $this->maxChunkSize = config('documents.chunk_settings.max_chunk_size', 300);
        $this->chunkOverlap = config('documents.chunk_settings.chunk_overlap', 50);
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
            'markdown' => $this->chunkByMarkdownHeaders($text),
            default => $this->chunkByParagraph($text),
        };

        Log::info('Document chunked', [
            'document_id' => $document->id,
            'strategy' => $strategy,
            'chunk_count' => count($rawChunks),
        ]);

        // Supprimer les anciens chunks
        $document->chunks()->delete();

        // Créer les nouveaux chunks
        $chunks = [];
        foreach ($rawChunks as $index => $chunkData) {
            // Métadonnées de base
            $metadata = [
                'strategy' => $strategy,
                'document_title' => $document->title ?? $document->original_name,
                'category' => $document->category,
            ];

            // Fusionner les métadonnées spécifiques au chunk (ex: markdown headers)
            if (isset($chunkData['metadata']) && is_array($chunkData['metadata'])) {
                $metadata = array_merge($metadata, $chunkData['metadata']);
            }

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
                'metadata' => $metadata,
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
     * Découpage par phrase - chaque phrase devient un chunk séparé
     */
    private function chunkBySentence(string $text): array
    {
        // Découper en phrases sur la ponctuation de fin
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Si on n'a qu'une seule phrase, essayer de découper sur d'autres ponctuations
        if (count($sentences) <= 1) {
            $sentences = preg_split('/(?<=[.!?;:])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        }

        // Convertir chaque phrase en chunk (sans regroupement)
        return $this->itemsToChunks($sentences);
    }

    /**
     * Découpage par paragraphe - chaque paragraphe/ligne devient un chunk séparé
     */
    private function chunkByParagraph(string $text): array
    {
        // Découper en paragraphes (double saut de ligne)
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $paragraphs = array_map('trim', $paragraphs);
        $paragraphs = array_filter($paragraphs);

        // Si on n'a qu'un seul paragraphe, découper par lignes simples
        if (count($paragraphs) <= 1) {
            $paragraphs = preg_split('/\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
            $paragraphs = array_map('trim', $paragraphs);
            $paragraphs = array_filter($paragraphs);
        }

        // Convertir chaque paragraphe en chunk (sans regroupement)
        return $this->itemsToChunks($paragraphs);
    }

    /**
     * Convertit une liste d'éléments en chunks individuels
     * Si un élément est trop grand, il est découpé en fixed_size
     */
    private function itemsToChunks(array $items): array
    {
        $chunks = [];
        $currentOffset = 0;

        foreach ($items as $item) {
            $item = trim($item);
            if (empty($item)) {
                continue;
            }

            // Si l'item est trop grand, le découper
            if ($this->estimateTokens($item) > $this->maxChunkSize) {
                $subChunks = $this->chunkByFixedSize($item);
                foreach ($subChunks as $subChunk) {
                    $subChunk['start_offset'] += $currentOffset;
                    $subChunk['end_offset'] += $currentOffset;
                    $chunks[] = $subChunk;
                }
            } else {
                $chunks[] = [
                    'content' => $item,
                    'start_offset' => $currentOffset,
                    'end_offset' => $currentOffset + mb_strlen($item),
                ];
            }

            $currentOffset += mb_strlen($item) + 1; // +1 pour le séparateur
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
     * Découpage par headers Markdown (optimisé pour HTML→Markdown et fichiers .md)
     *
     * Chaque section (header + contenu) devient un chunk.
     * Préserve la hiérarchie sémantique du document.
     */
    private function chunkByMarkdownHeaders(string $text): array
    {
        $chunks = [];
        $currentOffset = 0;

        // Pattern pour détecter les headers Markdown (# à ######)
        // Capture aussi le contenu jusqu'au prochain header ou fin de texte
        $pattern = '/^(#{1,6})\s+(.+?)$([\s\S]*?)(?=^#{1,6}\s|\z)/m';

        // Extraire le contenu avant le premier header (intro)
        $firstHeaderPos = preg_match('/^#{1,6}\s/m', $text, $matches, PREG_OFFSET_CAPTURE);
        if ($firstHeaderPos && $matches[0][1] > 0) {
            $intro = trim(mb_substr($text, 0, $matches[0][1]));
            if (!empty($intro)) {
                $chunks[] = [
                    'content' => $intro,
                    'start_offset' => 0,
                    'end_offset' => mb_strlen($intro),
                    'metadata' => [
                        'section_type' => 'intro',
                        'header_level' => 0,
                        'header_title' => null,
                    ],
                ];
                $currentOffset = $matches[0][1];
            }
        }

        // Extraire toutes les sections avec leurs headers
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $headerMarkers = $match[1][0];  // Les # du header
                $headerTitle = trim($match[2][0]);  // Le titre du header
                $sectionContent = isset($match[3]) ? trim($match[3][0]) : '';
                $headerLevel = strlen($headerMarkers);

                // Reconstruire la section complète : header + contenu
                $fullSection = $headerMarkers . ' ' . $headerTitle;
                if (!empty($sectionContent)) {
                    $fullSection .= "\n\n" . $sectionContent;
                }

                $sectionOffset = $match[0][1];

                // Si la section est trop grande, la découper en sous-chunks
                if ($this->estimateTokens($fullSection) > $this->maxChunkSize) {
                    // Garder le header comme contexte et découper le contenu
                    $headerContext = $headerMarkers . ' ' . $headerTitle;

                    if (!empty($sectionContent)) {
                        $subChunks = $this->chunkRecursive($sectionContent);
                        foreach ($subChunks as $index => $subChunk) {
                            // Préfixer chaque sous-chunk avec le header pour le contexte
                            $chunkContent = $index === 0
                                ? $headerContext . "\n\n" . $subChunk['content']
                                : "[" . $headerTitle . "]\n\n" . $subChunk['content'];

                            $chunks[] = [
                                'content' => $chunkContent,
                                'start_offset' => $sectionOffset + $subChunk['start_offset'],
                                'end_offset' => $sectionOffset + $subChunk['end_offset'],
                                'metadata' => [
                                    'section_type' => 'section_part',
                                    'header_level' => $headerLevel,
                                    'header_title' => $headerTitle,
                                    'part_index' => $index,
                                ],
                            ];
                        }
                    } else {
                        // Header seul sans contenu
                        $chunks[] = [
                            'content' => $fullSection,
                            'start_offset' => $sectionOffset,
                            'end_offset' => $sectionOffset + mb_strlen($fullSection),
                            'metadata' => [
                                'section_type' => 'section',
                                'header_level' => $headerLevel,
                                'header_title' => $headerTitle,
                            ],
                        ];
                    }
                } else {
                    // Section complète en un seul chunk
                    $chunks[] = [
                        'content' => $fullSection,
                        'start_offset' => $sectionOffset,
                        'end_offset' => $sectionOffset + mb_strlen($fullSection),
                        'metadata' => [
                            'section_type' => 'section',
                            'header_level' => $headerLevel,
                            'header_title' => $headerTitle,
                        ],
                    ];
                }
            }
        }

        // Si aucun header trouvé, fallback sur le chunking par paragraphe
        if (empty($chunks)) {
            Log::info('No markdown headers found, falling back to paragraph chunking');
            return $this->chunkByParagraph($text);
        }

        Log::info('Markdown chunking completed', [
            'total_sections' => count($chunks),
            'headers_found' => count($matches ?? []),
        ]);

        return $chunks;
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
