<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Pipeline\PipelineOrchestratorService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uuid'] = (string) Str::uuid();
        $data['uploaded_by'] = auth()->id();
        $data['extraction_status'] = 'pending';
        $data['source_type'] = $data['source_type'] ?? 'file';

        // Handle URL source
        if ($data['source_type'] === 'url' && !empty($data['source_url'])) {
            $urlData = $this->handleUrlSource($data['source_url']);
            $data = array_merge($data, $urlData);
        }

        // Handle file source
        if ($data['source_type'] === 'file' && isset($data['storage_path'])) {
            $storagePath = $data['storage_path'];
            $data['original_name'] = basename($storagePath);
            $extension = pathinfo($data['original_name'], PATHINFO_EXTENSION);
            $data['document_type'] = strtolower($extension);

            if (Storage::disk('local')->exists($storagePath)) {
                $fullPath = Storage::disk('local')->path($storagePath);
                $data['mime_type'] = mime_content_type($fullPath) ?: $this->getMimeTypeFromExtension($extension);
                $data['file_size'] = Storage::disk('local')->size($storagePath);
            } else {
                $data['mime_type'] = $this->getMimeTypeFromExtension($extension);
                $data['file_size'] = 0;
            }
        }

        return $data;
    }

    /**
     * Handle URL source - download content or prepare for crawling
     */
    protected function handleUrlSource(string $url): array
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Default to HTML for web pages
        if (empty($extension) || !in_array($extension, ['pdf', 'html', 'htm', 'md', 'png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            $extension = 'html';
        }

        // Try to fetch the content
        try {
            $response = Http::timeout(30)->get($url);

            if ($response->successful()) {
                $content = $response->body();
                $contentType = $response->header('Content-Type') ?? '';

                // Determine document type from content-type header
                if (str_contains($contentType, 'application/pdf')) {
                    $extension = 'pdf';
                } elseif (str_contains($contentType, 'text/html')) {
                    $extension = 'html';
                } elseif (str_contains($contentType, 'text/markdown')) {
                    $extension = 'md';
                } elseif (str_contains($contentType, 'image/')) {
                    preg_match('/image\/([\w]+)/', $contentType, $matches);
                    $extension = $matches[1] ?? 'png';
                }

                // Store the content
                $filename = Str::uuid() . '.' . $extension;
                $storagePath = 'documents/' . $filename;
                Storage::disk('local')->put($storagePath, $content);

                return [
                    'storage_path' => $storagePath,
                    'original_name' => basename($path) ?: ($parsed['host'] . '.' . $extension),
                    'document_type' => $extension,
                    'mime_type' => $contentType ?: $this->getMimeTypeFromExtension($extension),
                    'file_size' => strlen($content),
                ];
            }
        } catch (\Exception $e) {
            // Log error but continue - the pipeline will handle it
            \Log::warning("Failed to fetch URL: {$url}", ['error' => $e->getMessage()]);
        }

        // Return minimal data if fetch failed - storage_path is nullable for URL sources
        return [
            'storage_path' => null,
            'original_name' => basename($path) ?: $parsed['host'] ?? $url,
            'document_type' => $extension,
            'mime_type' => $this->getMimeTypeFromExtension($extension),
            'file_size' => 0,
        ];
    }

    /**
     * Après la création, démarrer le pipeline de traitement
     */
    protected function afterCreate(): void
    {
        /** @var Document $document */
        $document = $this->record;

        // Start the pipeline
        $orchestrator = app(PipelineOrchestratorService::class);
        $orchestrator->startPipeline($document);

        Notification::make()
            ->title('Pipeline démarré')
            ->body("Le document \"{$document->original_name}\" est en cours de traitement.")
            ->info()
            ->send();
    }

    /**
     * Détermine le type MIME à partir de l'extension
     */
    private function getMimeTypeFromExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html', 'htm' => 'text/html',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
