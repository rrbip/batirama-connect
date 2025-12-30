<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentChunk;
use App\Models\QrAtomiqueSetting;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QrGeneratorService
{
    protected EmbeddingService $embeddingService;
    protected QdrantService $qdrantService;

    public function __construct(
        EmbeddingService $embeddingService,
        QdrantService $qdrantService
    ) {
        $this->embeddingService = $embeddingService;
        $this->qdrantService = $qdrantService;
    }

    /**
     * Process a chunk to generate Q/R pairs and index to Qdrant
     *
     * @return array{useful: bool, knowledge_units: array, category: string, summary: string, qdrant_points_count: int}
     */
    public function processChunk(
        DocumentChunk $chunk,
        Document $document,
        array $config = []
    ): array {
        // Get Q/R settings from database configuration
        $qrSettings = QrAtomiqueSetting::getInstance();

        $model = $config['model'] ?? $qrSettings->getModelFor($document->agent);
        $temperature = $config['temperature'] ?? $qrSettings->temperature ?? 0.3;

        // Generate Q/R via LLM using configured prompt
        $llmResponse = $this->callLlm(
            $chunk->content,
            $chunk->parent_context,
            $model,
            $temperature,
            $qrSettings
        );

        // Parse response
        $result = $this->parseLlmResponse($llmResponse);

        // Handle category
        $categoryName = $result['category'] ?? 'DIVERS';
        $category = $this->findOrCreateCategory($categoryName);

        // Update chunk with Q/R data and raw LLM response
        $chunk->update([
            'useful' => $result['useful'],
            'knowledge_units' => $result['knowledge_units'] ?? [],
            'summary' => $result['summary'] ?? null,
            'category_id' => $category->id,
            'metadata' => array_merge($chunk->metadata ?? [], [
                'llm_raw_response' => $llmResponse,
                'llm_model' => $model,
                'llm_temperature' => $temperature,
                'llm_processed_at' => now()->toIso8601String(),
            ]),
        ]);

        // Index to Qdrant if useful
        $qdrantPointsCount = 0;
        if ($result['useful']) {
            $qdrantPointsCount = $this->indexToQdrant($chunk, $document, $result);
        }

        $chunk->update([
            'qdrant_points_count' => $qdrantPointsCount,
            'is_indexed' => $result['useful'],
            'indexed_at' => $result['useful'] ? now() : null,
        ]);

        Log::info("Chunk processed", [
            'chunk_id' => $chunk->id,
            'useful' => $result['useful'],
            'knowledge_units_count' => count($result['knowledge_units'] ?? []),
            'qdrant_points_count' => $qdrantPointsCount,
        ]);

        return [
            'useful' => $result['useful'],
            'knowledge_units' => $result['knowledge_units'] ?? [],
            'category' => $categoryName,
            'summary' => $result['summary'] ?? '',
            'qdrant_points_count' => $qdrantPointsCount,
        ];
    }

    /**
     * Call LLM to generate Q/R pairs
     */
    protected function callLlm(
        string $content,
        ?string $parentContext,
        string $model,
        float $temperature,
        QrAtomiqueSetting $qrSettings
    ): string {
        // Use settings for Ollama connection
        $ollamaHost = $qrSettings->ollama_host ?? config('ai.ollama.host', 'ollama');
        $ollamaPort = $qrSettings->ollama_port ?? config('ai.ollama.port', 11434);
        $ollamaUrl = "http://{$ollamaHost}:{$ollamaPort}";

        // Timeout 0 = illimité (les appels LLM peuvent prendre plusieurs minutes)
        $timeout = $qrSettings->timeout_seconds ?? 0;

        // Build prompt from configurable settings
        $prompt = $qrSettings->buildPrompt($content, $parentContext);

        // Timeout illimité pour les appels LLM
        $response = Http::timeout($timeout)
            ->connectTimeout(30)
            ->post("{$ollamaUrl}/api/generate", [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => $temperature,
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("LLM call failed: " . $response->body());
        }

        return $response->json('response', '');
    }

    /**
     * Parse LLM response to extract Q/R data
     */
    protected function parseLlmResponse(string $response): array
    {
        // Try to extract JSON from response
        $jsonMatch = preg_match('/\{[\s\S]*\}/', $response, $matches);

        if (!$jsonMatch) {
            Log::warning("Could not extract JSON from LLM response", [
                'response' => substr($response, 0, 500),
            ]);
            return [
                'useful' => false,
                'knowledge_units' => [],
                'category' => 'DIVERS',
                'summary' => '',
                'raw_content_clean' => '',
            ];
        }

        $json = $matches[0];
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("Invalid JSON in LLM response", [
                'error' => json_last_error_msg(),
                'json' => substr($json, 0, 500),
            ]);
            return [
                'useful' => false,
                'knowledge_units' => [],
                'category' => 'DIVERS',
                'summary' => '',
                'raw_content_clean' => '',
            ];
        }

        return [
            'useful' => $data['useful'] ?? false,
            'knowledge_units' => $data['knowledge_units'] ?? [],
            'category' => $data['category'] ?? 'DIVERS',
            'summary' => $data['summary'] ?? '',
            'raw_content_clean' => $data['raw_content_clean'] ?? '',
        ];
    }

    /**
     * Find or create a category
     */
    protected function findOrCreateCategory(string $name): DocumentCategory
    {
        // Normalize name: Title Case with proper UTF-8 support
        $normalizedName = $this->normalizeCategory($name);
        $slug = Str::slug($normalizedName);

        // First try exact slug match
        $category = DocumentCategory::where('slug', $slug)->first();

        // If not found, try fuzzy match on existing categories
        if (!$category) {
            $category = $this->findSimilarCategory($normalizedName, $slug);
        }

        if (!$category) {
            $category = DocumentCategory::create([
                'name' => $normalizedName,
                'slug' => $slug,
                'description' => "Catégorie générée automatiquement",
                'is_ai_generated' => true,
            ]);

            Log::info("Created new category", ['name' => $normalizedName, 'slug' => $slug]);
        }

        $category->incrementUsage();

        return $category;
    }

    /**
     * Normalize category name to Title Case with proper UTF-8 support
     */
    protected function normalizeCategory(string $name): string
    {
        // Trim and handle empty
        $name = trim($name);
        if (empty($name)) {
            return 'Divers';
        }

        // Convert to Title Case (first letter uppercase, rest lowercase) with UTF-8 support
        return mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Try to find a similar existing category
     */
    protected function findSimilarCategory(string $name, string $slug): ?DocumentCategory
    {
        // Get all categories for comparison
        $categories = DocumentCategory::all();

        foreach ($categories as $category) {
            // Check if slugs are similar (handles plurals, minor typos)
            $existingSlug = $category->slug;

            // Exact slug match (already checked, but double-check)
            if ($existingSlug === $slug) {
                return $category;
            }

            // One is prefix of the other (e.g., "renovation" vs "renovations")
            if (str_starts_with($existingSlug, $slug) || str_starts_with($slug, $existingSlug)) {
                $lengthDiff = abs(strlen($existingSlug) - strlen($slug));
                if ($lengthDiff <= 2) {
                    Log::info("Category fuzzy match", [
                        'input' => $name,
                        'matched' => $category->name,
                    ]);
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Index chunk to Qdrant with multiple points (Q/R pairs + source)
     */
    protected function indexToQdrant(
        DocumentChunk $chunk,
        Document $document,
        array $result
    ): int {
        $agent = $document->agent;

        if (!$agent || !$agent->qdrant_collection) {
            Log::warning("No Qdrant collection configured for agent", [
                'document_id' => $document->id,
            ]);
            return 0;
        }

        $collection = $agent->qdrant_collection;
        $points = [];
        $pointIds = [];

        // Create Q/R points
        foreach ($result['knowledge_units'] as $index => $unit) {
            $question = $unit['question'] ?? '';
            $answer = $unit['answer'] ?? '';

            if (empty($question) || empty($answer)) {
                continue;
            }

            // Generate embedding for question
            $embedding = $this->embeddingService->embed($question);

            $pointId = Str::uuid()->toString();
            $pointIds[] = $pointId;

            $points[] = [
                'id' => $pointId,
                'vector' => $embedding,
                'payload' => [
                    'type' => 'qa_pair',
                    'category' => $result['category'],
                    'display_text' => $answer,
                    'question' => $question,
                    'source_doc' => $document->title ?? $document->original_name,
                    'parent_context' => $chunk->parent_context,
                    'chunk_id' => $chunk->id,
                    'document_id' => $document->id,
                    'agent_id' => $agent->id,
                ],
            ];
        }

        // Create source material point
        $summaryContent = ($result['summary'] ?? '') . ' ' . ($result['raw_content_clean'] ?? $chunk->content);
        $sourceEmbedding = $this->embeddingService->embed($summaryContent);

        $sourcePointId = Str::uuid()->toString();
        $pointIds[] = $sourcePointId;

        $points[] = [
            'id' => $sourcePointId,
            'vector' => $sourceEmbedding,
            'payload' => [
                'type' => 'source_material',
                'category' => $result['category'],
                'display_text' => $chunk->content,
                'summary' => $result['summary'] ?? '',
                'source_doc' => $document->title ?? $document->original_name,
                'parent_context' => $chunk->parent_context,
                'chunk_id' => $chunk->id,
                'document_id' => $document->id,
                'agent_id' => $agent->id,
            ],
        ];

        // Upsert to Qdrant
        if (!empty($points)) {
            $this->qdrantService->upsert($collection, $points);
        }

        // Update chunk with point IDs
        $chunk->update(['qdrant_point_ids' => $pointIds]);

        return count($points);
    }
}
