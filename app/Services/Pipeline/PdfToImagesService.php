<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class PdfToImagesService
{
    /**
     * Convert a PDF document to images
     *
     * @return array{images: array, output_path: string, page_count: int}
     */
    public function convert(Document $document, array $config = []): array
    {
        $dpi = $config['dpi'] ?? 300;
        $format = $config['format'] ?? 'png';

        $pdfPath = Storage::disk('local')->path($document->storage_path);

        if (!file_exists($pdfPath)) {
            throw new \RuntimeException("PDF file not found: {$pdfPath}");
        }

        // Create output directory for images
        $outputDir = "pipeline/{$document->uuid}/pdf_images";
        $outputPath = Storage::disk('local')->path($outputDir);

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        // Build pdftoppm command
        $outputPrefix = "{$outputPath}/page";

        $command = [
            'pdftoppm',
            '-' . $format,
            '-r', (string) $dpi,
            $pdfPath,
            $outputPrefix,
        ];

        Log::info("Converting PDF to images", [
            'document_id' => $document->id,
            'command' => implode(' ', $command),
        ]);

        $process = new Process($command);
        $process->setTimeout(600); // 10 minutes timeout
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // Get list of generated images
        $images = [];
        $files = glob("{$outputPath}/page-*.{$format}");

        if ($files === false) {
            $files = [];
        }

        sort($files, SORT_NATURAL);

        foreach ($files as $index => $file) {
            $relativePath = str_replace(Storage::disk('local')->path(''), '', $file);
            $images[] = [
                'index' => $index,
                'path' => $relativePath,
                'filename' => basename($file),
                'size' => filesize($file),
            ];
        }

        $pageCount = count($images);

        Log::info("PDF converted to images", [
            'document_id' => $document->id,
            'page_count' => $pageCount,
            'output_dir' => $outputDir,
        ]);

        return [
            'images' => $images,
            'output_path' => $outputDir,
            'page_count' => $pageCount,
        ];
    }

    /**
     * Get the total size of all images
     */
    public function getTotalSize(array $images): int
    {
        return array_sum(array_column($images, 'size'));
    }

    /**
     * Format bytes to human readable
     */
    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
