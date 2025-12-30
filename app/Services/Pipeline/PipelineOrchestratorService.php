<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\Document;
use App\Jobs\Pipeline\ProcessPdfToImagesJob;
use App\Jobs\Pipeline\ProcessImagesToMarkdownJob;
use App\Jobs\Pipeline\ProcessHtmlToMarkdownJob;
use App\Jobs\Pipeline\ProcessMarkdownToQrJob;
use Illuminate\Support\Facades\Log;

class PipelineOrchestratorService
{
    /**
     * Pipeline definitions per document type
     * Each pipeline is a sequence of steps that will be executed
     */
    public const PIPELINES = [
        'pdf' => [
            ['name' => 'pdf_to_images', 'job' => ProcessPdfToImagesJob::class],
            ['name' => 'images_to_markdown', 'job' => ProcessImagesToMarkdownJob::class],
            ['name' => 'markdown_to_qr', 'job' => ProcessMarkdownToQrJob::class],
        ],
        'image' => [
            ['name' => 'image_to_markdown', 'job' => ProcessImagesToMarkdownJob::class],
            ['name' => 'markdown_to_qr', 'job' => ProcessMarkdownToQrJob::class],
        ],
        'html' => [
            ['name' => 'html_to_markdown', 'job' => ProcessHtmlToMarkdownJob::class],
            ['name' => 'markdown_to_qr', 'job' => ProcessMarkdownToQrJob::class],
        ],
        'markdown' => [
            ['name' => 'markdown_to_qr', 'job' => ProcessMarkdownToQrJob::class],
        ],
    ];

    /**
     * Default tools per step
     */
    public const DEFAULT_TOOLS = [
        'pdf_to_images' => 'pdftoppm',
        'images_to_markdown' => 'vision_llm',
        'image_to_markdown' => 'vision_llm',
        'html_to_markdown' => 'html_converter',
        'markdown_to_qr' => 'qr_atomique',
    ];

    /**
     * Start the pipeline for a document
     */
    public function startPipeline(Document $document, array $toolOverrides = [], bool $autoChain = true): void
    {
        $documentType = $this->detectDocumentType($document);
        $pipeline = self::PIPELINES[$documentType] ?? null;

        if (!$pipeline) {
            Log::warning("No pipeline defined for document type: {$documentType}", [
                'document_id' => $document->id,
            ]);
            return;
        }

        // Initialize pipeline_steps
        $steps = [];
        foreach ($pipeline as $step) {
            $tool = $toolOverrides[$step['name']] ?? self::DEFAULT_TOOLS[$step['name']] ?? null;
            $steps[] = [
                'step_name' => $step['name'],
                'tool_used' => $tool,
                'tool_config' => $this->getToolConfig($step['name'], $tool, $document),
                'status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'duration_ms' => null,
                'input_summary' => null,
                'output_summary' => null,
                'output_path' => null,
                'output_data' => null,
                'error_message' => null,
                'error_trace' => null,
            ];
        }

        $document->update([
            'pipeline_steps' => [
                'status' => 'running',
                'started_at' => now()->toIso8601String(),
                'completed_at' => null,
                'steps' => $steps,
            ],
            'extraction_status' => 'processing',
        ]);

        // Dispatch the first step
        $this->dispatchStep($document, 0, $autoChain);
    }

    /**
     * Dispatch a specific step in the pipeline
     */
    public function dispatchStep(Document $document, int $stepIndex, bool $autoChain = true): void
    {
        $pipelineData = $document->pipeline_steps;
        $steps = $pipelineData['steps'] ?? [];

        if (!isset($steps[$stepIndex])) {
            Log::warning("Step index {$stepIndex} not found in pipeline", [
                'document_id' => $document->id,
            ]);
            return;
        }

        $step = $steps[$stepIndex];
        $documentType = $this->detectDocumentType($document);
        $pipelineDefinition = self::PIPELINES[$documentType] ?? [];

        if (!isset($pipelineDefinition[$stepIndex])) {
            Log::warning("Pipeline definition not found for step {$stepIndex}", [
                'document_id' => $document->id,
                'document_type' => $documentType,
            ]);
            return;
        }

        $jobClass = $pipelineDefinition[$stepIndex]['job'];

        // Dispatch the job
        dispatch(new $jobClass($document, $stepIndex, $autoChain));

        Log::info("Dispatched pipeline step", [
            'document_id' => $document->id,
            'step_index' => $stepIndex,
            'step_name' => $step['step_name'],
            'job' => $jobClass,
            'auto_chain' => $autoChain,
        ]);
    }

    /**
     * Relaunch a specific step (manual mode - no auto chain)
     */
    public function relaunchStep(Document $document, int $stepIndex, ?string $newTool = null): void
    {
        $pipelineData = $document->pipeline_steps;
        $steps = $pipelineData['steps'] ?? [];

        if (!isset($steps[$stepIndex])) {
            throw new \InvalidArgumentException("Step index {$stepIndex} not found");
        }

        // Update tool if provided
        if ($newTool !== null) {
            $steps[$stepIndex]['tool_used'] = $newTool;
            $steps[$stepIndex]['tool_config'] = $this->getToolConfig(
                $steps[$stepIndex]['step_name'],
                $newTool,
                $document
            );
        }

        // Reset step status
        $steps[$stepIndex]['status'] = 'pending';
        $steps[$stepIndex]['started_at'] = null;
        $steps[$stepIndex]['completed_at'] = null;
        $steps[$stepIndex]['duration_ms'] = null;
        $steps[$stepIndex]['output_summary'] = null;
        $steps[$stepIndex]['output_data'] = null;
        $steps[$stepIndex]['error_message'] = null;
        $steps[$stepIndex]['error_trace'] = null;

        // Update global pipeline status back to running
        $pipelineData['status'] = 'running';
        $pipelineData['completed_at'] = null;
        $pipelineData['steps'] = $steps;

        $document->update([
            'pipeline_steps' => $pipelineData,
            'extraction_status' => 'processing',
        ]);

        // Dispatch without auto chain (manual mode)
        $this->dispatchStep($document, $stepIndex, false);
    }

    /**
     * Continue pipeline from a specific step (after manual validation)
     */
    public function continueFromStep(Document $document, int $stepIndex): void
    {
        $pipelineData = $document->pipeline_steps;
        $steps = $pipelineData['steps'] ?? [];

        $nextStepIndex = $stepIndex + 1;

        if (isset($steps[$nextStepIndex])) {
            $this->dispatchStep($document, $nextStepIndex, true);
        } else {
            // Pipeline completed
            $this->markPipelineCompleted($document);
        }
    }

    /**
     * Update step status when starting
     */
    public function markStepStarted(Document $document, int $stepIndex, string $inputSummary = null): void
    {
        $this->updateStepData($document, $stepIndex, [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'input_summary' => $inputSummary,
        ]);
    }

    /**
     * Update step status when completed successfully
     */
    public function markStepCompleted(
        Document $document,
        int $stepIndex,
        string $outputSummary = null,
        string $outputPath = null,
        mixed $outputData = null
    ): void {
        $pipelineData = $document->pipeline_steps;
        $steps = $pipelineData['steps'] ?? [];
        $step = $steps[$stepIndex] ?? null;

        $startedAt = $step['started_at'] ?? null;
        $durationMs = null;
        if ($startedAt) {
            $durationMs = (int) (now()->diffInMilliseconds(new \DateTime($startedAt)));
        }

        $this->updateStepData($document, $stepIndex, [
            'status' => 'success',
            'completed_at' => now()->toIso8601String(),
            'duration_ms' => $durationMs,
            'output_summary' => $outputSummary,
            'output_path' => $outputPath,
            'output_data' => $outputData,
        ]);
    }

    /**
     * Update step status when failed
     */
    public function markStepFailed(
        Document $document,
        int $stepIndex,
        string $errorMessage,
        string $errorTrace = null
    ): void {
        $pipelineData = $document->pipeline_steps;
        $steps = $pipelineData['steps'] ?? [];
        $step = $steps[$stepIndex] ?? null;

        $startedAt = $step['started_at'] ?? null;
        $durationMs = null;
        if ($startedAt) {
            $durationMs = (int) (now()->diffInMilliseconds(new \DateTime($startedAt)));
        }

        $this->updateStepData($document, $stepIndex, [
            'status' => 'error',
            'completed_at' => now()->toIso8601String(),
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
            'error_trace' => $errorTrace,
        ]);

        // Update global pipeline status to failed
        $pipelineData = $document->fresh()->pipeline_steps ?? ['steps' => []];
        $pipelineData['status'] = 'failed';
        $pipelineData['completed_at'] = now()->toIso8601String();

        $document->update([
            'pipeline_steps' => $pipelineData,
            'extraction_status' => 'failed',
        ]);
    }

    /**
     * Mark the entire pipeline as completed
     */
    public function markPipelineCompleted(Document $document): void
    {
        // Update global pipeline status
        $pipelineData = $document->pipeline_steps ?? ['steps' => []];
        $pipelineData['status'] = 'completed';
        $pipelineData['completed_at'] = now()->toIso8601String();

        $document->update([
            'pipeline_steps' => $pipelineData,
            'extraction_status' => 'completed',
            'extracted_at' => now(),
        ]);

        Log::info("Pipeline completed", ['document_id' => $document->id]);
    }

    /**
     * Check if all steps are completed and mark pipeline as completed if so
     */
    public function checkAndCompletePipeline(Document $document): bool
    {
        $pipelineData = $document->pipeline_steps ?? ['steps' => []];
        $steps = $pipelineData['steps'] ?? [];

        if (empty($steps)) {
            return false;
        }

        // Check if all steps are successful
        foreach ($steps as $step) {
            if ($step['status'] !== 'success') {
                return false;
            }
        }

        // All steps are completed - mark pipeline as completed
        $this->markPipelineCompleted($document);
        return true;
    }

    /**
     * Get the next step index in the pipeline
     */
    public function getNextStepIndex(Document $document, int $currentStepIndex): ?int
    {
        $pipelineData = $document->pipeline_steps;
        $steps = $pipelineData['steps'] ?? [];

        $nextIndex = $currentStepIndex + 1;
        return isset($steps[$nextIndex]) ? $nextIndex : null;
    }

    /**
     * Detect document type from MIME type
     */
    public function detectDocumentType(Document $document): string
    {
        $mimeType = $document->mime_type;

        if (str_contains($mimeType, 'pdf')) {
            return 'pdf';
        }

        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_contains($mimeType, 'html')) {
            return 'html';
        }

        if (str_contains($mimeType, 'markdown') || $document->document_type === 'md') {
            return 'markdown';
        }

        // Default to markdown for text files
        if (str_starts_with($mimeType, 'text/')) {
            return 'markdown';
        }

        return 'unknown';
    }

    /**
     * Get tool configuration based on step, tool and document
     */
    protected function getToolConfig(string $stepName, ?string $tool, Document $document): array
    {
        $agent = $document->agent;

        switch ($stepName) {
            case 'pdf_to_images':
                return [
                    'dpi' => 300,
                    'format' => 'png',
                ];

            case 'images_to_markdown':
            case 'image_to_markdown':
                return [
                    'model' => $agent?->model ?? config('ai.ollama.default_model'),
                    'temperature' => 0.3,
                ];

            case 'html_to_markdown':
                return [
                    'preserve_structure' => true,
                ];

            case 'markdown_to_qr':
                return [
                    'threshold' => config('documents.qr_atomique.threshold', 1500),
                    'model' => $agent?->model ?? config('ai.ollama.default_model'),
                ];

            default:
                return [];
        }
    }

    /**
     * Update step data in pipeline_steps JSON
     */
    protected function updateStepData(Document $document, int $stepIndex, array $data): void
    {
        $pipelineData = $document->pipeline_steps ?? ['steps' => []];
        $steps = $pipelineData['steps'];

        if (isset($steps[$stepIndex])) {
            $steps[$stepIndex] = array_merge($steps[$stepIndex], $data);
            $pipelineData['steps'] = $steps;
            $document->update(['pipeline_steps' => $pipelineData]);
        }
    }
}
