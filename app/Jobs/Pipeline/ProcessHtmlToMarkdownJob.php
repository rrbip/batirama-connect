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

            // If HTML came from extracted_text (no storage_path), save it to a file
            // This ensures future relaunches can use the original HTML
            if (empty($document->storage_path)) {
                $htmlStoragePath = "documents/" . $document->uuid . '.html';
                Storage::disk('local')->put($htmlStoragePath, $html);
                $document->update(['storage_path' => $htmlStoragePath]);
                Log::info("Saved original HTML to storage", ['path' => $htmlStoragePath]);
            }

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
     *
     * Priority order:
     * 1. storage_path - Original HTML file (always preferred for relaunch)
     * 2. source_url - Fetch from URL and save to storage
     * 3. extracted_text - Fallback for crawled content without file
     */
    protected function getHtmlContent(Document $document): string
    {
        // Priority 1: Try reading from local storage (original HTML file)
        // This ensures relaunch uses original HTML, not old markdown from extracted_text
        if ($document->storage_path) {
            $path = Storage::disk('local')->path($document->storage_path);
            if (file_exists($path)) {
                Log::info("Reading HTML from storage_path", ['path' => $document->storage_path]);
                return file_get_contents($path);
            }
        }

        // Priority 2: Try fetching from source_url (for URL-based documents)
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

        // Priority 3: Fallback to extracted_text (for crawled content without stored file)
        // Note: This may contain old markdown if pipeline was already run, but only used
        // when no original file is available
        if (!empty($document->extracted_text)) {
            Log::warning("Using extracted_text as HTML source (no storage_path or source_url)", [
                'document_id' => $document->id,
            ]);
            return $document->extracted_text;
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

        // Remove nav, header, footer, aside (can be nested, so run multiple times)
        for ($i = 0; $i < 3; $i++) {
            $html = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', '', $html);
            $html = preg_replace('/<header\b[^>]*>(.*?)<\/header>/is', '', $html);
            $html = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', '', $html);
            $html = preg_replace('/<aside\b[^>]*>(.*?)<\/aside>/is', '', $html);
        }

        // Remove common navigation classes/ids
        $html = preg_replace('/<[^>]*(class|id)=["\'][^"\']*\b(nav|menu|sidebar|breadcrumb|footer|header|toolbar|topbar|bottombar)[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is', '', $html);

        // Remove form elements (login forms, search boxes, etc.)
        $html = preg_replace('/<form\b[^>]*>(.*?)<\/form>/is', '', $html);

        // Remove button elements
        $html = preg_replace('/<button\b[^>]*>(.*?)<\/button>/is', '', $html);

        // Remove empty header tags (h1-h6 with no content or just whitespace)
        $html = preg_replace('/<h([1-6])\b[^>]*>\s*<\/h\1>/is', '', $html);

        // Remove comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Remove noscript content
        $html = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', $html);

        // Remove iframe
        $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html);

        return $html;
    }

    /**
     * Clean up generated markdown
     */
    protected function cleanMarkdown(string $markdown): string
    {
        // ============================================================
        // ÉTAPE 1: Supprimer les éléments non-textuels
        // ============================================================

        // Supprimer TOUTES les images (pas seulement standalone)
        // ![alt text](url) ou ![](url)
        $markdown = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $markdown);

        // Supprimer les liens CTA courants (même avec texte après)
        // [Essai Gratuit](url) Demander une démo → ""
        $ctaPatterns = [
            'Essai', 'Demo', 'Démo', 'Inscription', 'S\'inscrire', 'Souscrire',
            'Connexion', 'Se connecter', 'Login', 'Sign up', 'Sign in', 'Register',
            'Contact', 'Demander', 'Télécharger', 'Download', 'Acheter', 'Buy',
            'Commander', 'Réserver', 'Book', 'Start', 'Commencer', 'Get started',
        ];
        $ctaRegex = implode('|', array_map('preg_quote', $ctaPatterns));
        $markdown = preg_replace('/\[(' . $ctaRegex . ')[^\]]*\]\([^)]+\)[^\n]*/im', '', $markdown);

        // Supprimer les liens internes de navigation (chemins relatifs courts)
        // [Solutions](/) [Tarifs](/tarifs) [Blog](/blog)
        $markdown = preg_replace('/\[[^\]]{1,25}\]\(\/[^)]{0,30}\)/', '', $markdown);

        // Supprimer les liens vers des ancres #
        // [Close](#close) → Close
        $markdown = preg_replace('/\[([^\]]*)\]\(#[^)]*\)/', '$1', $markdown);

        // ============================================================
        // ÉTAPE 2: Nettoyer les lignes problématiques
        // ============================================================

        // Supprimer les lignes qui ne contiennent qu'un seul mot court (navigation résiduelle)
        // "Solutions" "Tarifs" "Contact" seuls sur une ligne
        $markdown = preg_replace('/^[A-ZÀ-Ÿ][a-zà-ÿ]{2,15}$/m', '', $markdown);

        // Supprimer les listes de navigation (lignes consécutives de liens)
        $markdown = preg_replace('/^(\s*-\s*\[[^\]]+\]\([^)]+\)\s*\n){2,}/m', '', $markdown);

        // Supprimer les lignes avec seulement des liens (même inline)
        // "- [Home](/) - [About](/about)" → ""
        $markdown = preg_replace('/^[\s\-\*]*(\[[^\]]+\]\([^)]+\)[\s\-\*]*)+$/m', '', $markdown);

        // ============================================================
        // ÉTAPE 3: Nettoyer les headers
        // ============================================================

        // Séparer les headers collés au texte
        $markdown = preg_replace('/([^\n])(#{1,6}\s)/m', "$1\n\n$2", $markdown);
        $markdown = preg_replace('/([^\n])\n(#{1,6}\s)/m', "$1\n\n$2", $markdown);

        // Supprimer les headers vides
        $markdown = preg_replace('/^#{1,6}\s*$/m', '', $markdown);

        // ============================================================
        // ÉTAPE 4: Nettoyage final
        // ============================================================

        // Supprimer les lignes avec seulement ponctuation/symboles
        $markdown = preg_replace('/^\s*[\|\-\*\_\.\,\:\;\!\?\#\@\&]+\s*$/m', '', $markdown);

        // Supprimer les lignes vides en début de document
        $markdown = preg_replace('/^\s*\n+/', '', $markdown);

        // Réduire les lignes vides multiples (max 2 newlines)
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        // Trim final
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
