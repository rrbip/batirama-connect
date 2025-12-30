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
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

class ProcessHtmlToMarkdownJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 300; // 5 minutes

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

        Log::info("Processing HTML to markdown", [
            'document_id' => $document->id,
            'step_index' => $this->stepIndex,
        ]);

        // Mark step as started
        $inputSummary = sprintf(
            "%s (%s)",
            $document->original_name,
            $document->getFileSizeForHumans()
        );
        $orchestrator->markStepStarted($document, $this->stepIndex, $inputSummary);

        try {
            // Get HTML content
            $html = $this->getHtmlContent($document);

            // Convert to Markdown
            $markdown = $this->convertToMarkdown($html);

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

            Log::info("HTML to markdown completed", [
                'document_id' => $document->id,
                'token_count' => $tokenCount,
            ]);

            // Chain to next step if auto mode
            if ($this->autoChain) {
                $nextStepIndex = $orchestrator->getNextStepIndex($document, $this->stepIndex);
                if ($nextStepIndex !== null) {
                    $orchestrator->dispatchStep($document->fresh(), $nextStepIndex, true);
                } else {
                    $orchestrator->markPipelineCompleted($document->fresh());
                }
            }

        } catch (Throwable $e) {
            Log::error("HTML to markdown failed", [
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
     * Get HTML content from document, storage, or URL
     */
    protected function getHtmlContent(Document $document): string
    {
        // If document has extracted_text, use it (from crawl)
        if (!empty($document->extracted_text)) {
            return $document->extracted_text;
        }

        // Try reading from local storage
        if ($document->storage_path) {
            $path = Storage::disk('local')->path($document->storage_path);
            if (file_exists($path)) {
                return file_get_contents($path);
            }
        }

        // Try fetching from source_url (for URL-based documents)
        if (!empty($document->source_url)) {
            Log::info("Fetching HTML from source_url", ['url' => $document->source_url]);

            $response = Http::timeout(60)->get($document->source_url);

            if ($response->successful()) {
                $content = $response->body();

                // Store for future use
                $storagePath = "documents/" . $document->uuid . '.html';
                Storage::disk('local')->put($storagePath, $content);

                $document->update([
                    'storage_path' => $storagePath,
                    'file_size' => strlen($content),
                ]);

                return $content;
            }

            throw new \RuntimeException("Failed to fetch URL: " . $response->status());
        }

        throw new \RuntimeException("No HTML content found for document");
    }

    /**
     * Convert HTML to Markdown
     */
    protected function convertToMarkdown(string $html): string
    {
        // Clean HTML first
        $html = $this->cleanHtml($html);

        // Use League HTML to Markdown converter
        // header_style 'atx' ensures all headers use # syntax (not setext === or ---)
        // This is required for MarkdownChunkerService to detect headers properly
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'remove_nodes' => 'script style nav footer header aside',
            'hard_break' => true,
            'header_style' => 'atx',
        ]);

        $markdown = $converter->convert($html);

        // Clean up markdown
        $markdown = $this->cleanMarkdown($markdown);

        return $markdown;
    }

    /**
     * Clean HTML before conversion
     */
    protected function cleanHtml(string $html): string
    {
        // Remove script and style tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // Remove nav, header, footer, aside
        $html = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', '', $html);
        $html = preg_replace('/<header\b[^>]*>(.*?)<\/header>/is', '', $html);
        $html = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', '', $html);
        $html = preg_replace('/<aside\b[^>]*>(.*?)<\/aside>/is', '', $html);

        // Remove comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        return $html;
    }

    /**
     * Clean up generated markdown
     */
    protected function cleanMarkdown(string $markdown): string
    {
        // Remove excessive blank lines
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        // Trim whitespace
        $markdown = trim($markdown);

        return $markdown;
    }

    /**
     * Estimate token count (rough approximation)
     */
    protected function estimateTokenCount(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProcessHtmlToMarkdownJob failed permanently", [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
