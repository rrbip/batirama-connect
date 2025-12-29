<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
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

        // Extraire le nom original et le type du fichier uploadé
        if (isset($data['storage_path'])) {
            $storagePath = $data['storage_path'];
            $data['original_name'] = basename($storagePath);
            $extension = pathinfo($data['original_name'], PATHINFO_EXTENSION);
            $data['document_type'] = strtolower($extension);

            // Utiliser Storage facade pour compatibilité Docker
            if (Storage::disk('local')->exists($storagePath)) {
                $fullPath = Storage::disk('local')->path($storagePath);
                $data['mime_type'] = mime_content_type($fullPath) ?: $this->getMimeTypeFromExtension($extension);
                $data['file_size'] = Storage::disk('local')->size($storagePath);
            } else {
                // Fallback si le fichier n'est pas encore accessible
                $data['mime_type'] = $this->getMimeTypeFromExtension($extension);
                $data['file_size'] = 0;
            }
        }

        // Déterminer la stratégie de chunking optimale (après avoir déterminé le type)
        if (empty($data['chunk_strategy'])) {
            $data['chunk_strategy'] = $this->getOptimalChunkStrategy(
                $data['document_type'] ?? '',
                $data['agent_id'] ?? null
            );
        }

        return $data;
    }

    /**
     * Après la création, dispatcher le job de traitement
     */
    protected function afterCreate(): void
    {
        /** @var Document $document */
        $document = $this->record;

        // Dispatcher le job de traitement (extraction + chunking + indexation)
        ProcessDocumentJob::dispatch($document);

        Notification::make()
            ->title('Traitement en cours')
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
            default => 'application/octet-stream',
        };
    }

    /**
     * Détermine la stratégie de chunking optimale selon le type de document
     *
     * - md, html, htm : utilise 'markdown' (découpage par headers)
     * - Autres : utilise la stratégie par défaut de l'agent
     */
    private function getOptimalChunkStrategy(string $documentType, ?int $agentId): string
    {
        // Pour Markdown et HTML, la stratégie 'markdown' est optimale
        if (in_array(strtolower($documentType), ['md', 'html', 'htm'])) {
            return 'markdown';
        }

        // Pour les autres types, utiliser la stratégie de l'agent
        if ($agentId) {
            $agent = \App\Models\Agent::find($agentId);
            return $agent?->getDefaultChunkStrategy() ?? 'sentence';
        }

        return 'sentence';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
