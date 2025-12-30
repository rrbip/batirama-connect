<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\QdrantService;
use App\Services\Pipeline\MarkdownChunkerService;
use App\Services\Pipeline\PipelineOrchestratorService;
use App\Services\Pipeline\QrGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMarkdownToQrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // No automatic retry - LLM timeouts are usually not recoverable
    public int $timeout = 0; // No timeout - can be long

    public function __construct(
        public Document $document,
        public int $stepIndex,
        public bool $autoChain = true
    ) {
        $this->queue = 'pipeline';
    }

    public function handle(
        MarkdownChunkerService $chunkerService,
        QrGeneratorService $qrService,
        PipelineOrchestratorService $orchestrator,
        QdrantService $qdrantService
    ): void {
        $document = $this->document->fresh();

        Log::info("Processing markdown to Q/R", [
            'document_id' => $document->id,
            'step_index' => $this->stepIndex,
        ]);

        // Get step config
        $pipelineData = $document->pipeline_steps;
        $step = $pipelineData['steps'][$this->stepIndex] ?? null;
        $config = $step['tool_config'] ?? [];

        // Get markdown content
        $markdown = $document->extracted_text;

        if (empty($markdown)) {
            throw new \RuntimeException("No markdown content to process");
        }

        // Mark step as started
        $inputSummary = sprintf("%d caractères Markdown", strlen($markdown));
        $orchestrator->markStepStarted($document, $this->stepIndex, $inputSummary);

        try {
            // Clean up existing Qdrant points before reprocessing
            $this->cleanupQdrantPoints($document, $qdrantService);

            // Force delete existing chunks to avoid conflicts
            $document->chunks()->forceDelete();

            // Chunk the markdown
            $threshold = $config['threshold'] ?? 1500;
            $chunkerService->setThreshold($threshold);
            $chunks = $chunkerService->chunk($markdown);

            Log::info("Markdown chunked", [
                'document_id' => $document->id,
                'chunk_count' => count($chunks),
            ]);

            // Create chunks and process Q/R
            $totalQdrantPoints = 0;
            $usefulCount = 0;

            foreach ($chunks as $chunkData) {
                // Use updateOrCreate to avoid unique constraint violations
                $chunk = DocumentChunk::updateOrCreate(
                    [
                        'document_id' => $document->id,
                        'chunk_index' => $chunkData['chunk_index'],
                    ],
                    [
                        'content' => $chunkData['content'],
                        'original_content' => $chunkData['content'],
                        'parent_context' => $chunkData['parent_context'],
                        'start_offset' => $chunkData['start_offset'],
                        'end_offset' => $chunkData['end_offset'],
                        'token_count' => $this->estimateTokenCount($chunkData['content']),
                        'content_hash' => md5($chunkData['content']),
                        'created_at' => now(),
                    ]
                );

                // Process Q/R for this chunk
                $result = $qrService->processChunk($chunk, $document, $config);

                $totalQdrantPoints += $result['qdrant_points_count'];
                if ($result['useful']) {
                    $usefulCount++;
                }

                Log::debug("Chunk processed", [
                    'chunk_id' => $chunk->id,
                    'chunk_index' => $chunkData['chunk_index'],
                    'useful' => $result['useful'],
                    'qdrant_points' => $result['qdrant_points_count'],
                ]);
            }

            // Update document
            $document->update([
                'chunk_count' => count($chunks),
                'is_indexed' => true,
                'indexed_at' => now(),
            ]);

            // Mark step as completed
            $outputSummary = sprintf(
                "%d chunks, %d points Qdrant (%d utiles)",
                count($chunks),
                $totalQdrantPoints,
                $usefulCount
            );

            $orchestrator->markStepCompleted(
                $document,
                $this->stepIndex,
                $outputSummary,
                null,
                [
                    'chunk_count' => count($chunks),
                    'useful_count' => $usefulCount,
                    'qdrant_points_count' => $totalQdrantPoints,
                ]
            );

            Log::info("Markdown to Q/R completed", [
                'document_id' => $document->id,
                'chunk_count' => count($chunks),
                'useful_count' => $usefulCount,
                'qdrant_points' => $totalQdrantPoints,
            ]);

            // Chain to next step or complete pipeline
            if ($this->autoChain) {
                $nextStepIndex = $orchestrator->getNextStepIndex($document, $this->stepIndex);
                if ($nextStepIndex !== null) {
                    $orchestrator->dispatchStep($document->fresh(), $nextStepIndex, true);
                } else {
                    $orchestrator->markPipelineCompleted($document->fresh());
                }
            }

        } catch (Throwable $e) {
            Log::error("Markdown to Q/R failed", [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $orchestrator->markStepFailed(
                $document,
                $this->stepIndex,
                $e->getMessage(),
                $e->getTraceAsString()
            );

            throw $e;
        }
    }

    /**
     * Estimate token count (rough approximation)
     */
    protected function estimateTokenCount(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Clean up existing Qdrant points before reprocessing
     * This prevents orphan points when relaunching the pipeline
     */
    protected function cleanupQdrantPoints(Document $document, QdrantService $qdrantService): void
    {
        $agent = $document->agent;

        if (!$agent || empty($agent->qdrant_collection)) {
            Log::info("No Qdrant collection configured, skipping cleanup", [
                'document_id' => $document->id,
            ]);
            return;
        }

        // Collect all existing point IDs from chunks
        $existingChunks = $document->chunks()->get();
        $pointIdsToDelete = [];

        foreach ($existingChunks as $chunk) {
            // Handle both old format (single ID) and new format (array of IDs)
            if (!empty($chunk->qdrant_point_ids) && is_array($chunk->qdrant_point_ids)) {
                $pointIdsToDelete = array_merge($pointIdsToDelete, $chunk->qdrant_point_ids);
            } elseif (!empty($chunk->qdrant_point_id)) {
                $pointIdsToDelete[] = $chunk->qdrant_point_id;
            }
        }

        if (empty($pointIdsToDelete)) {
            Log::info("No existing Qdrant points to delete", [
                'document_id' => $document->id,
            ]);
            return;
        }

        // Delete points from Qdrant
        try {
            $qdrantService->delete($agent->qdrant_collection, $pointIdsToDelete);

            Log::info("Cleaned up existing Qdrant points", [
                'document_id' => $document->id,
                'collection' => $agent->qdrant_collection,
                'points_deleted' => count($pointIdsToDelete),
            ]);
        } catch (\Exception $e) {
            // Log but don't fail - points might already be deleted or collection might not exist
            Log::warning("Failed to delete some Qdrant points (may already be deleted)", [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProcessMarkdownToQrJob failed permanently", [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
