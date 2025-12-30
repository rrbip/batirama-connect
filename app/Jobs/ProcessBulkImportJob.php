<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\Pipeline\PipelineOrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessBulkImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Le nombre de tentatives maximum.
     */
    public int $tries = 1;

    /**
     * Le timeout en secondes.
     */
    public int $timeout = 3600; // 1 heure pour les gros imports

    /**
     * @param int $agentId
     * @param array<array{path: string, original_name: string, parent_context: ?string}> $files
     */
    public function __construct(
        public int $agentId,
        public array $files
    ) {
        // Forcer l'utilisation de la queue 'default'
        $this->onQueue('default');
    }

    public function handle(PipelineOrchestratorService $orchestrator): void
    {
        Log::info('Starting bulk import', [
            'agent_id' => $this->agentId,
            'file_count' => count($this->files),
        ]);

        $imported = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->files as $fileData) {
            try {
                $document = $this->importFile($fileData);

                // Start the pipeline for this document
                $orchestrator->startPipeline($document);

                $imported++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'file' => $fileData['original_name'],
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to import file', [
                    'file' => $fileData['original_name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Bulk import completed', [
            'agent_id' => $this->agentId,
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    /**
     * Importe un fichier individuel et retourne le Document créé
     */
    private function importFile(array $fileData): Document
    {
        $sourcePath = $fileData['path'];
        $originalName = $fileData['original_name'];
        $parentContext = $fileData['parent_context'] ?? null;

        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Le fichier source n'existe pas: {$sourcePath}");
        }

        // Déterminer l'extension et le type de document
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Générer un nom de fichier unique
        $filename = Str::uuid() . '.' . $extension;
        $storagePath = 'documents/' . $filename;

        // Copier le fichier vers le storage permanent
        $content = file_get_contents($sourcePath);
        Storage::disk('local')->put($storagePath, $content);

        // Supprimer le fichier temporaire
        @unlink($sourcePath);

        // Extraire le titre du nom de fichier (sans extension)
        $title = $this->cleanFileName($originalName);

        // Déterminer le type MIME
        $mimeType = $this->getMimeType($extension);

        // Créer le document
        $document = Document::create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $this->agentId,
            'source_type' => 'file',
            'storage_path' => $storagePath,
            'original_name' => $originalName,
            'title' => $title,
            'description' => $parentContext ? "Importé depuis: {$parentContext}" : null,
            'document_type' => $extension,
            'mime_type' => $mimeType,
            'file_size' => strlen($content),
            'extraction_status' => 'pending',
            'is_indexed' => false,
        ]);

        Log::info('Document created from bulk import', [
            'document_id' => $document->id,
            'title' => $title,
            'parent_context' => $parentContext,
        ]);

        return $document;
    }

    /**
     * Nettoie le nom de fichier pour en faire un titre lisible
     */
    private function cleanFileName(string $filename): string
    {
        // Enlever l'extension
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Remplacer les underscores et tirets par des espaces
        $name = str_replace(['_', '-'], ' ', $name);

        // Supprimer les caractères spéciaux
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);

        // Normaliser les espaces multiples
        $name = preg_replace('/\s+/', ' ', $name);

        // Capitaliser correctement
        $name = mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');

        return $name;
    }

    /**
     * Détermine le type MIME à partir de l'extension
     */
    private function getMimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html', 'htm' => 'text/html',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
