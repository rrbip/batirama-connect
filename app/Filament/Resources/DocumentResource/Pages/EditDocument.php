<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Jobs\ProcessDocumentJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reprocess')
                ->label('Retraiter')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Retraiter le document')
                ->modalDescription('Le document sera ré-extrait, re-découpé et ré-indexé.')
                ->action(function () {
                    $this->record->update([
                        'extraction_status' => 'pending',
                        'extraction_error' => null,
                        'is_indexed' => false,
                    ]);

                    ProcessDocumentJob::dispatch($this->record);

                    Notification::make()
                        ->title('Traitement relancé')
                        ->body('Le document est en cours de retraitement.')
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Mutate les données du formulaire avant sauvegarde
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Si un nouveau fichier a été uploadé
        if (!empty($data['new_file'])) {
            $newFilePath = $data['new_file'];

            // Supprimer l'ancien fichier si différent
            $oldPath = $this->record->storage_path;
            if ($oldPath && $oldPath !== $newFilePath && Storage::disk('local')->exists($oldPath)) {
                Storage::disk('local')->delete($oldPath);
            }

            // Mettre à jour les métadonnées
            $data['storage_path'] = $newFilePath;
            $data['original_name'] = basename($newFilePath);
            $data['file_size'] = Storage::disk('local')->size($newFilePath);
            $data['document_type'] = strtolower(pathinfo($newFilePath, PATHINFO_EXTENSION));

            // Réinitialiser le statut pour retraitement
            $data['extraction_status'] = 'pending';
            $data['extraction_error'] = null;
            $data['extracted_text'] = null;
            $data['extracted_at'] = null;
            $data['is_indexed'] = false;
            $data['indexed_at'] = null;
            $data['chunk_count'] = 0;
        }

        // Supprimer la clé new_file car ce n'est pas un champ de la base
        unset($data['new_file']);

        return $data;
    }

    /**
     * Après sauvegarde, relancer le traitement si le fichier a changé
     */
    protected function afterSave(): void
    {
        // Si le document est en attente de traitement (fichier remplacé)
        if ($this->record->extraction_status === 'pending') {
            // Supprimer les anciens chunks
            $this->record->chunks()->delete();

            // Dispatcher le job de traitement
            ProcessDocumentJob::dispatch($this->record);

            Notification::make()
                ->title('Fichier remplacé')
                ->body('Le document sera automatiquement retraité et ré-indexé.')
                ->success()
                ->send();
        }
    }
}
