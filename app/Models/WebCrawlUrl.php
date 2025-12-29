<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\DocumentExtractorService;
use App\Services\Marketplace\LanguageDetector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WebCrawlUrl extends Model
{
    use HasFactory;

    protected $fillable = [
        'url',
        'url_hash',
        'canonical_url',
        'canonical_hash',
        'duplicate_of_id',
        'storage_path',
        'content_hash',
        'http_status',
        'content_type',
        'content_length',
        'locale',
        'last_modified',
        'etag',
    ];

    protected $casts = [
        'content_length' => 'integer',
        'http_status' => 'integer',
    ];

    /**
     * Les crawls qui ont découvert cette URL
     */
    public function crawls(): BelongsToMany
    {
        return $this->belongsToMany(WebCrawl::class, 'web_crawl_url_crawl', 'crawl_url_id', 'crawl_id')
            ->withPivot([
                'parent_id',
                'depth',
                'status',
                'error_message',
                'retry_count',
                'fetched_at',
            ])
            ->withTimestamps();
    }

    /**
     * Les entrées d'indexation par agent pour cette URL
     */
    public function agentIndexEntries(): HasMany
    {
        return $this->hasMany(AgentWebCrawlUrl::class, 'web_crawl_url_id');
    }

    /**
     * Les documents créés à partir de cette URL (tous agents)
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'crawl_url_id');
    }

    /**
     * L'URL originale dont celle-ci est un doublon
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(WebCrawlUrl::class, 'duplicate_of_id');
    }

    /**
     * Les URLs qui sont des doublons de celle-ci
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(WebCrawlUrl::class, 'duplicate_of_id');
    }

    /**
     * Vérifie si cette URL est un doublon
     */
    public function isDuplicate(): bool
    {
        return $this->duplicate_of_id !== null;
    }

    /**
     * Génère le hash de l'URL normalisée
     */
    public static function generateUrlHash(string $url): string
    {
        // Normaliser l'URL avant de hasher
        $normalized = self::normalizeUrl($url);
        return hash('sha256', $normalized);
    }

    /**
     * Normalise une URL pour déduplication
     */
    public static function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (!$parsed) {
            return $url;
        }

        // Lowercase scheme et host
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host'] ?? '');

        // Path sans trailing slash (sauf racine)
        $path = $parsed['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // Trier les query params
        $query = '';
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            ksort($params);
            $query = '?' . http_build_query($params);
        }

        // Ignorer le fragment (#anchor)
        return "{$scheme}://{$host}{$path}{$query}";
    }

    /**
     * Vérifie si le contenu est de type HTML
     */
    public function isHtml(): bool
    {
        return str_contains($this->content_type ?? '', 'text/html');
    }

    /**
     * Vérifie si le contenu est un PDF
     */
    public function isPdf(): bool
    {
        return str_contains($this->content_type ?? '', 'application/pdf');
    }

    /**
     * Vérifie si le contenu est une image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->content_type ?? '', 'image/');
    }

    /**
     * Vérifie si c'est une réponse de succès HTTP
     */
    public function isSuccess(): bool
    {
        return $this->http_status >= 200 && $this->http_status < 300;
    }

    /**
     * Vérifie si c'est une redirection
     */
    public function isRedirect(): bool
    {
        return $this->http_status >= 300 && $this->http_status < 400;
    }

    /**
     * Vérifie si c'est une erreur client
     */
    public function isClientError(): bool
    {
        return $this->http_status >= 400 && $this->http_status < 500;
    }

    /**
     * Vérifie si c'est une erreur serveur
     */
    public function isServerError(): bool
    {
        return $this->http_status >= 500;
    }

    /**
     * Get the stored HTML content.
     */
    public function getContent(): ?string
    {
        if (empty($this->storage_path)) {
            return null;
        }

        // Use Storage facade (same as admin preview) for consistency
        if (!Storage::disk('local')->exists($this->storage_path)) {
            return null;
        }

        return Storage::disk('local')->get($this->storage_path);
    }

    /**
     * Extract text content from stored file.
     * For HTML: removes tags, scripts, styles.
     * For PDF: uses pdftotext or pdfparser (or OCR if specified).
     * For other types: returns null (no extraction available).
     *
     * @param string|null $pdfExtractionMethod Method for PDF: auto, text, ocr
     */
    public function getTextContent(?string $pdfExtractionMethod = null): ?string
    {
        $content = $this->getContent();

        if (empty($content)) {
            return null;
        }

        // For HTML, convert to Markdown to preserve semantic structure
        if ($this->isHtml()) {
            return $this->convertHtmlToMarkdown($content);
        }

        // For PDF, use proper text extraction
        if ($this->isPdf()) {
            return $this->extractTextFromPdf($pdfExtractionMethod ?? 'auto');
        }

        // For plain text files
        if (str_contains($this->content_type ?? '', 'text/plain') ||
            str_contains($this->content_type ?? '', 'text/markdown')) {
            return trim($content);
        }

        // For other types (images, binary docs), no text extraction available
        return null;
    }

    /**
     * Extract text from PDF using the specified method.
     *
     * @param string $method Extraction method: auto, text, ocr, vision
     */
    private function extractTextFromPdf(string $method = 'auto'): ?string
    {
        if (empty($this->storage_path)) {
            return null;
        }

        $fullPath = Storage::disk('local')->path($this->storage_path);

        if (!file_exists($fullPath)) {
            return null;
        }

        // Vision mode: use Ollama Vision model
        if ($method === 'vision') {
            return $this->extractPdfWithVision($fullPath);
        }

        // OCR mode: use Tesseract only
        if ($method === 'ocr') {
            return $this->extractPdfWithOcr($fullPath);
        }

        // Text mode or Auto mode: try text extraction first
        $text = $this->extractPdfWithPdftotext($fullPath);

        if (!empty($text)) {
            return $text;
        }

        // Fallback to smalot/pdfparser
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($fullPath);
            $text = $pdf->getText();

            if (!empty($text)) {
                return $this->cleanPdfText($text);
            }
        } catch (\Exception $e) {
            // Silently fail, PDF might be encrypted or corrupted
        }

        // Auto mode: if text extraction failed, try OCR as fallback
        if ($method === 'auto') {
            return $this->extractPdfWithOcr($fullPath);
        }

        return null;
    }

    /**
     * Extract PDF text using OCR (Tesseract via pdftoppm).
     */
    private function extractPdfWithOcr(string $path): ?string
    {
        // Check if pdftoppm and tesseract are available
        $pdftoppmCheck = @shell_exec('pdftoppm -v 2>&1');
        $tesseractCheck = @shell_exec('tesseract --version 2>&1');

        if (!$pdftoppmCheck || !str_contains($tesseractCheck ?? '', 'tesseract')) {
            return null;
        }

        $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . uniqid();
        @mkdir($tempDir, 0755, true);

        try {
            // Convert PDF to images (PNG, 300 DPI)
            $escapedPath = escapeshellarg($path);
            $escapedTempDir = escapeshellarg($tempDir);
            $result = @shell_exec("pdftoppm -png -r 300 {$escapedPath} {$escapedTempDir}/page 2>&1");

            // Find generated images
            $images = glob($tempDir . '/page-*.png');
            if (empty($images)) {
                $images = glob($tempDir . '/page*.png');
            }

            if (empty($images)) {
                return null;
            }

            sort($images);

            // OCR each page
            $fullText = '';
            foreach ($images as $imagePath) {
                $escapedImage = escapeshellarg($imagePath);
                $pageText = @shell_exec("tesseract {$escapedImage} stdout -l fra+eng --psm 3 2>/dev/null");
                if ($pageText) {
                    $fullText .= $pageText . "\n\n";
                }
            }

            return !empty($fullText) ? $this->cleanPdfText($fullText) : null;

        } finally {
            // Cleanup temp files
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($tempDir);
        }
    }

    /**
     * Extract PDF text using pdftotext command.
     */
    private function extractPdfWithPdftotext(string $path): ?string
    {
        // Check if pdftotext is available
        $checkResult = @shell_exec('pdftotext -v 2>&1');
        if ($checkResult === null || !str_contains($checkResult, 'pdftotext')) {
            return null;
        }

        // Extract text with pdftotext
        $escapedPath = escapeshellarg($path);
        $output = @shell_exec("pdftotext -enc UTF-8 {$escapedPath} - 2>/dev/null");

        if ($output === null || strlen(trim($output)) < 10) {
            return null;
        }

        return $this->cleanPdfText($output);
    }

    /**
     * Extract PDF text using Ollama Vision model.
     */
    private function extractPdfWithVision(string $path): ?string
    {
        try {
            $visionService = app(\App\Services\VisionExtractorService::class);

            if (!$visionService->isAvailable()) {
                \Illuminate\Support\Facades\Log::warning('Vision service not available for PDF extraction', [
                    'url' => $this->url,
                ]);
                return null;
            }

            $documentId = 'webcrawl_' . $this->id;
            $result = $visionService->extractFromPdf($path, $documentId);

            if ($result['success'] && !empty($result['markdown'])) {
                \Illuminate\Support\Facades\Log::info('Vision PDF extraction successful', [
                    'url' => $this->url,
                    'pages_processed' => $result['metadata']['pages_processed'] ?? 0,
                    'markdown_length' => strlen($result['markdown']),
                ]);

                return $result['markdown'];
            }

            return null;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Vision PDF extraction failed', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Clean extracted PDF text.
     */
    private function cleanPdfText(string $text): string
    {
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove excessive whitespace while keeping paragraph structure
        $text = preg_replace('/[^\S\n]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Detect and set the locale from content.
     * For HTML: uses lang attribute, URL patterns, then content analysis.
     * For other documents (PDF, etc.): uses URL patterns and content analysis.
     *
     * @param string|null $pdfExtractionMethod Method for PDF text extraction: auto, text, ocr
     */
    public function detectLocale(?string $pdfExtractionMethod = null): ?string
    {
        $detector = app(LanguageDetector::class);
        $locale = null;

        // For HTML pages, try lang attribute first (most reliable)
        if ($this->isHtml()) {
            $html = $this->getContent();
            if (!empty($html)) {
                $locale = $detector->detectFromHtmlLangAttribute($html);
            }
        }

        // Try URL patterns (works for all content types)
        if (!$locale) {
            $locale = $detector->detectFromUrl($this->url);
        }

        // For non-HTML or if still no locale, try content analysis
        if (!$locale) {
            $content = $this->getTextContent($pdfExtractionMethod);
            if (!empty($content)) {
                $locale = $detector->detectFromContent(mb_substr($content, 0, 5000));
            }
        }

        return $locale;
    }

    /**
     * Detect locale and save to database.
     *
     * @param string|null $pdfExtractionMethod Method for PDF text extraction: auto, text, ocr
     */
    public function detectAndSaveLocale(?string $pdfExtractionMethod = null): ?string
    {
        $locale = $this->detectLocale($pdfExtractionMethod);

        if ($locale && $locale !== $this->locale) {
            $this->update(['locale' => $locale]);
        }

        return $locale;
    }

    /**
     * Get locale name for display.
     */
    public function getLocaleNameAttribute(): ?string
    {
        if (!$this->locale) {
            return null;
        }

        $detector = app(LanguageDetector::class);
        return $detector->getLocaleName($this->locale);
    }

    /**
     * Convert HTML content to Markdown for better RAG indexing.
     * Uses DocumentExtractorService for consistent conversion across the app.
     * Original HTML is preserved on disk for product extraction.
     *
     * @param string $html Raw HTML content
     * @return string Converted Markdown
     */
    private function convertHtmlToMarkdown(string $html): string
    {
        try {
            $extractorService = app(DocumentExtractorService::class);
            $result = $extractorService->convertHtmlToMarkdown($html);

            Log::info('WebCrawlUrl: HTML to Markdown conversion', [
                'url_id' => $this->id,
                'url' => $this->url,
                'html_size' => $result['metadata']['html_size'],
                'markdown_size' => $result['metadata']['markdown_size'],
                'compression_ratio' => $result['metadata']['compression_ratio'],
                'elements' => $result['metadata']['elements_detected'],
            ]);

            return $result['markdown'];

        } catch (\Exception $e) {
            // Fallback to basic strip_tags if converter fails
            Log::warning('WebCrawlUrl: HTML to Markdown failed, using fallback', [
                'url_id' => $this->id,
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);

            $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
            $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
            $text = strip_tags($html);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);

            return trim($text);
        }
    }
}
