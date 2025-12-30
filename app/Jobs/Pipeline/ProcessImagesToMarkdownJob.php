<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\Document;
use App\Services\Pipeline\PipelineOrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessImagesToMarkdownJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 0; // No timeout - can be long for many pages

    public function __construct(
        public Document $document,
        public int $stepIndex,
        public bool $autoChain = true
    ) {
        $this->queue = 'pipeline';
    }

    public function handle(PipelineOrchestratorService $orchestrator): void
    {
        $document = $this->document->fresh();

        Log::info("Processing images to markdown", [
            'document_id' => $document->id,
            'step_index' => $this->stepIndex,
        ]);

        // Get step config and previous step output
        $pipelineData = $document->pipeline_steps;
        $step = $pipelineData['steps'][$this->stepIndex] ?? null;
        $config = $step['tool_config'] ?? [];

        // Get images from previous step
        $previousStep = $pipelineData['steps'][$this->stepIndex - 1] ?? null;
        $images = $previousStep['output_data'] ?? [];

        if (empty($images)) {
            // Single image document
            $images = [[
                'path' => $document->storage_path,
                'filename' => $document->original_name,
            ]];
        }

        // Mark step as started
        $inputSummary = sprintf("%d image(s) à traiter", count($images));
        $orchestrator->markStepStarted($document, $this->stepIndex, $inputSummary);

        try {
            $markdown = $this->processImages($images, $config, $document);

            // Store markdown
            $outputPath = "pipeline/{$document->uuid}/markdown.md";
            Storage::disk('local')->put($outputPath, $markdown);

            // Update document extracted_text
            $document->update(['extracted_text' => $markdown]);

            // Mark step as completed
            $tokenCount = $this->estimateTokenCount($markdown);
            $outputSummary = sprintf("%d tokens Markdown", $tokenCount);

            $orchestrator->markStepCompleted(
                $document,
                $this->stepIndex,
                $outputSummary,
                $outputPath,
                $markdown
            );

            Log::info("Images to markdown completed", [
                'document_id' => $document->id,
                'token_count' => $tokenCount,
            ]);

            // Chain to next step or complete pipeline
            $nextStepIndex = $orchestrator->getNextStepIndex($document, $this->stepIndex);

            if ($nextStepIndex !== null && $this->autoChain) {
                // Auto mode: dispatch next step
                $orchestrator->dispatchStep($document->fresh(), $nextStepIndex, true);
            } else {
                // Manual mode or last step: check if all steps are done
                $orchestrator->checkAndCompletePipeline($document->fresh());
            }

        } catch (Throwable $e) {
            Log::error("Images to markdown failed", [
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
     * Process images sequentially through Vision LLM
     */
    protected function processImages(array $images, array $config, Document $document): string
    {
        $agent = $document->agent;
        $model = $config['model'] ?? $agent?->model ?? config('ai.ollama.default_model');
        $temperature = $config['temperature'] ?? 0.3;

        $ollamaHost = $agent?->ollama_host ?? config('ai.ollama.host');
        $ollamaPort = $agent?->ollama_port ?? config('ai.ollama.port');
        $ollamaUrl = "http://{$ollamaHost}:{$ollamaPort}";

        $markdownParts = [];

        foreach ($images as $index => $image) {
            $imagePath = Storage::disk('local')->path($image['path']);

            if (!file_exists($imagePath)) {
                Log::warning("Image not found", ['path' => $imagePath]);
                continue;
            }

            Log::info("Processing image", [
                'document_id' => $document->id,
                'image_index' => $index + 1,
                'total' => count($images),
            ]);

            $imageData = base64_encode(file_get_contents($imagePath));

            $prompt = <<<PROMPT
Analyse cette image et extrait son contenu textuel en Markdown structuré.

RÈGLES:
1. Préserve la hiérarchie des titres (# ## ### etc.)
2. Préserve les listes et tableaux
3. Ignore les éléments de navigation et décoration
4. Retourne UNIQUEMENT le contenu Markdown, pas d'explication

MARKDOWN:
PROMPT;

            $response = Http::timeout(300)
                ->post("{$ollamaUrl}/api/generate", [
                    'model' => $model,
                    'prompt' => $prompt,
                    'images' => [$imageData],
                    'stream' => false,
                    'options' => [
                        'temperature' => $temperature,
                    ],
                ]);

            if ($response->successful()) {
                $content = $response->json('response', '');
                if (!empty(trim($content))) {
                    $markdownParts[] = trim($content);
                }
            } else {
                Log::warning("Vision LLM call failed for image", [
                    'image_index' => $index,
                    'status' => $response->status(),
                ]);
            }
        }

        return implode("\n\n---\n\n", $markdownParts);
    }

    /**
     * Estimate token count (rough approximation)
     */
    protected function estimateTokenCount(string $text): int
    {
        // Rough estimate: ~4 characters per token
        return (int) ceil(strlen($text) / 4);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProcessImagesToMarkdownJob failed permanently", [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
