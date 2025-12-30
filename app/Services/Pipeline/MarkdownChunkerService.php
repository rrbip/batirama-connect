<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Support\Facades\Log;

class MarkdownChunkerService
{
    protected int $threshold;

    public function __construct()
    {
        $this->threshold = config('documents.qr_atomique.threshold', 1500);
    }

    /**
     * Chunk markdown content based on structure
     *
     * Rules:
     * 1. Split on ### (h3) headers
     * 2. If chunk > threshold chars, split by paragraphs
     * 3. Preserve parent context (breadcrumbs)
     *
     * @return array<array{content: string, parent_context: string, start_offset: int, end_offset: int}>
     */
    public function chunk(string $markdown, int $threshold = null): array
    {
        $threshold = $threshold ?? $this->threshold;
        $chunks = [];

        // Parse the markdown structure
        $structure = $this->parseStructure($markdown);

        foreach ($structure as $section) {
            $sectionChunks = $this->chunkSection($section, $threshold);
            $chunks = array_merge($chunks, $sectionChunks);
        }

        // Re-index chunks
        foreach ($chunks as $index => &$chunk) {
            $chunk['chunk_index'] = $index;
        }

        Log::info("Markdown chunked", [
            'total_chunks' => count($chunks),
            'threshold' => $threshold,
        ]);

        return $chunks;
    }

    /**
     * Parse markdown structure to extract hierarchy
     *
     * @return array<array{level: int, title: string, content: string, parents: array, start: int, end: int}>
     */
    protected function parseStructure(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $sections = [];
        $currentParents = []; // Stack of parent titles by level
        $currentSection = null;
        $currentStart = 0;
        $offset = 0;
        $contentBeforeFirstHeader = '';
        $contentBeforeFirstHeaderEnd = 0;
        $foundFirstHeader = false;

        foreach ($lines as $lineIndex => $line) {
            $lineLength = strlen($line) + 1; // +1 for newline

            // Check if line is a header
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                $foundFirstHeader = true;
                $level = strlen($matches[1]);
                $title = trim($matches[2]);

                // Save previous section if exists
                if ($currentSection !== null) {
                    $currentSection['end'] = $offset - 1;
                    $currentSection['content'] = trim($currentSection['content']);
                    if (!empty($currentSection['content'])) {
                        $sections[] = $currentSection;
                    }
                }

                // Update parent stack
                // Remove all parents at same or lower level
                $currentParents = array_filter($currentParents, fn($p) => $p['level'] < $level);
                $currentParents[] = ['level' => $level, 'title' => $title];

                // Build parent context (breadcrumbs)
                $parentTitles = array_map(fn($p) => $p['title'], $currentParents);
                $parentContext = implode(' > ', array_slice($parentTitles, 0, -1));

                // Start new section
                $currentSection = [
                    'level' => $level,
                    'title' => $title,
                    'content' => '',
                    'parents' => $parentTitles,
                    'parent_context' => $parentContext,
                    'start' => $offset,
                    'end' => null,
                ];
            } elseif ($currentSection !== null) {
                // Add line to current section content
                $currentSection['content'] .= $line . "\n";
            } elseif (!$foundFirstHeader) {
                // Capture content before the first header
                $contentBeforeFirstHeader .= $line . "\n";
                $contentBeforeFirstHeaderEnd = $offset + $lineLength;
            }

            $offset += $lineLength;
        }

        // Save last section
        if ($currentSection !== null) {
            $currentSection['end'] = $offset;
            $currentSection['content'] = trim($currentSection['content']);
            if (!empty($currentSection['content'])) {
                $sections[] = $currentSection;
            }
        }

        // If we found content before headers, add it as a section
        $contentBeforeFirstHeader = trim($contentBeforeFirstHeader);
        if (!empty($contentBeforeFirstHeader)) {
            array_unshift($sections, [
                'level' => 0,
                'title' => '',
                'content' => $contentBeforeFirstHeader,
                'parents' => [],
                'parent_context' => '',
                'start' => 0,
                'end' => $contentBeforeFirstHeaderEnd,
            ]);
        }

        // FALLBACK: If NO headers were found at all, treat entire content as one section
        if (empty($sections) && !empty(trim($markdown))) {
            Log::info("No headers found in markdown, treating entire content as one section");
            $sections[] = [
                'level' => 0,
                'title' => '',
                'content' => trim($markdown),
                'parents' => [],
                'parent_context' => '',
                'start' => 0,
                'end' => strlen($markdown),
            ];
        }

        return $sections;
    }

    /**
     * Chunk a section based on threshold
     *
     * @return array<array{content: string, parent_context: string, start_offset: int, end_offset: int}>
     */
    protected function chunkSection(array $section, int $threshold): array
    {
        $content = $section['content'];
        $parentContext = $section['parent_context'];

        // If section has a title, add it to parent context
        if (!empty($section['title'])) {
            $parentContext = !empty($parentContext)
                ? $parentContext . ' > ' . $section['title']
                : $section['title'];
        }

        // If content is within threshold, return as single chunk
        if (strlen($content) <= $threshold) {
            return [[
                'content' => $content,
                'parent_context' => $parentContext,
                'start_offset' => $section['start'],
                'end_offset' => $section['end'],
            ]];
        }

        // Split by paragraphs (double newlines)
        $paragraphs = preg_split('/\n\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        if ($paragraphs === false) {
            $paragraphs = [$content];
        }

        $chunks = [];
        $currentChunk = '';
        $chunkStart = $section['start'];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if (empty($paragraph)) {
                continue;
            }

            // If adding this paragraph would exceed threshold, save current chunk
            if (!empty($currentChunk) && strlen($currentChunk) + strlen($paragraph) + 2 > $threshold) {
                $chunks[] = [
                    'content' => trim($currentChunk),
                    'parent_context' => $parentContext,
                    'start_offset' => $chunkStart,
                    'end_offset' => $chunkStart + strlen($currentChunk),
                ];
                $chunkStart = $chunkStart + strlen($currentChunk) + 2;
                $currentChunk = '';
            }

            $currentChunk .= $paragraph . "\n\n";
        }

        // Save remaining chunk
        if (!empty(trim($currentChunk))) {
            $chunks[] = [
                'content' => trim($currentChunk),
                'parent_context' => $parentContext,
                'start_offset' => $chunkStart,
                'end_offset' => $section['end'],
            ];
        }

        return $chunks;
    }

    /**
     * Set the character threshold
     */
    public function setThreshold(int $threshold): self
    {
        $this->threshold = $threshold;
        return $this;
    }
}
