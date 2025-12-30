<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\Document;
use App\Services\Pipeline\PdfToImagesService;
use App\Services\Pipeline\PipelineOrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPdfToImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 600; // 10 minutes

    public function __construct(
        public Document $document,
        public int $stepIndex,
        public bool $autoChain = true
    ) {
        $this->queue = 'pipeline';
    }

    public function handle(
        PdfToImagesService $pdfService,
        PipelineOrchestratorService $orchestrator
    ): void {
        $document = $this->document->fresh();

        Log::info("Processing PDF to images", [
            'document_id' => $document->id,
            'step_index' => $this->stepIndex,
        ]);

        // Get step config
        $pipelineData = $document->pipeline_steps;
        $step = $pipelineData['steps'][$this->stepIndex] ?? null;
        $config = $step['tool_config'] ?? [];

        // Mark step as started
        $inputSummary = sprintf(
            "%s (%s)",
            $document->original_name,
            $document->getFileSizeForHumans()
        );
        $orchestrator->markStepStarted($document, $this->stepIndex, $inputSummary);

        try {
            // Convert PDF to images
            $result = $pdfService->convert($document, $config);

            // Mark step as completed
            $outputSummary = sprintf(
                "%d images (%s)",
                $result['page_count'],
                $pdfService->formatBytes($pdfService->getTotalSize($result['images']))
            );

            $orchestrator->markStepCompleted(
                $document,
                $this->stepIndex,
                $outputSummary,
                $result['output_path'],
                $result['images']
            );

            Log::info("PDF to images completed", [
                'document_id' => $document->id,
                'page_count' => $result['page_count'],
            ]);

            // Chain to next step or complete pipeline
            $nextStepIndex = $orchestrator->getNextStepIndex($document, $this->stepIndex);

            if ($nextStepIndex !== null && $this->autoChain) {
                $orchestrator->dispatchStep($document->fresh(), $nextStepIndex, true);
            } elseif ($nextStepIndex === null) {
                $orchestrator->markPipelineCompleted($document->fresh());
            }

        } catch (Throwable $e) {
            Log::error("PDF to images failed", [
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

    public function failed(Throwable $exception): void
    {
        Log::error("ProcessPdfToImagesJob failed permanently", [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
