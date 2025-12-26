<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
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
     * @param array<array{path: string, original_name: string, category: ?string}> $files
     */
    public function __construct(
        public int $agentId,
        public array $files
    ) {}

    public function handle(): void
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
                $this->importFile($fileData);
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

        // Notification (si système de notification disponible)
        // On pourrait envoyer un email ou une notification Filament ici
    }

    /**
     * Importe un fichier individuel
     */
    private function importFile(array $fileData): void
    {
        $sourcePath = $fileData['path'];
        $originalName = $fileData['original_name'];
        $category = $fileData['category'];

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

        // Créer le document
        $document = Document::create([
            'agent_id' => $this->agentId,
            'storage_path' => $storagePath,
            'original_name' => $originalName,
            'title' => $title,
            'document_type' => $extension,
            'category' => $category,
            'file_size' => strlen($content),
            'extraction_status' => 'pending',
            'is_indexed' => false,
        ]);

        Log::info('Document created from bulk import', [
            'document_id' => $document->id,
            'title' => $title,
            'category' => $category,
        ]);

        // Dispatcher le job de traitement du document
        ProcessDocumentJob::dispatch($document);
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
}
