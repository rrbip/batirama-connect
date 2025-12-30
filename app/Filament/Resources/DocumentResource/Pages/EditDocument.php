<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Jobs\EnrichChunksWithLlmJob;
use App\Jobs\IndexDocumentChunksJob;
use App\Jobs\ProcessDocumentJob;
use App\Jobs\ProcessLlmChunkingJob;
use App\Services\DocumentChunkerService;
use App\Services\Pipeline\PipelineOrchestratorService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * Relance le pipeline à partir d'une étape spécifique
     */
    public function relaunchFromStep(int $stepIndex): void
    {
        $orchestrator = app(PipelineOrchestratorService::class);
        $orchestrator->relaunchStep($this->record, $stepIndex);

        Notification::make()
            ->title('Étape relancée')
            ->body("L'étape " . ($stepIndex + 1) . " a été remise en file d'attente.")
            ->success()
            ->send();

        // Refresh the record to update the view
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('chunks')
                ->label('Gérer les chunks')
                ->icon('heroicon-o-squares-2x2')
                ->color('info')
                ->visible(fn () => $this->record->chunk_count > 0)
                ->url(fn () => DocumentResource::getUrl('chunks', ['record' => $this->record])),

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

            Actions\Action::make('rechunk')
                ->label('Re-chunker')
                ->icon('heroicon-o-scissors')
                ->color('success')
                ->visible(fn () => !empty($this->record->extracted_text))
                ->requiresConfirmation()
                ->modalHeading('Re-découper le document')
                ->modalDescription(fn () => $this->record->chunk_strategy === 'llm_assisted'
                    ? 'Le texte extrait sera re-découpé par le LLM (catégories, résumés, mots-clés). Les chunks existants seront supprimés.'
                    : 'Le texte extrait sera re-découpé selon la stratégie configurée. Les chunks existants seront supprimés.')
                ->action(function () {
                    // Sauvegarder d'abord les changements du formulaire (texte extrait modifié)
                    $this->record->save();

                    // Supprimer les anciens chunks
                    $this->record->chunks()->delete();

                    // Réinitialiser les flags d'indexation
                    $this->record->update([
                        'is_indexed' => false,
                        'indexed_at' => null,
                        'chunk_count' => 0,
                        'extraction_status' => 'chunking',
                    ]);

                    $strategy = $this->record->chunk_strategy ?? 'sentence';

                    if ($strategy === 'llm_assisted') {
                        // Utiliser le job LLM dédié
                        ProcessLlmChunkingJob::dispatch($this->record, reindex: true);

                        Notification::make()
                            ->title('Chunking LLM lancé')
                            ->body('Le document sera re-découpé par le LLM puis ré-indexé. Consultez la queue llm-chunking.')
                            ->success()
                            ->send();
                    } else {
                        // Utiliser le chunker classique directement
                        try {
                            $chunker = app(DocumentChunkerService::class);
                            $chunks = $chunker->chunk($this->record);

                            $this->record->update([
                                'chunk_count' => count($chunks),
                                'extraction_status' => 'completed',
                            ]);

                            // Dispatcher l'indexation
                            IndexDocumentChunksJob::dispatch($this->record);

                            Notification::make()
                                ->title('Re-chunking terminé')
                                ->body(count($chunks) . ' chunks créés. Indexation en cours...')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            $this->record->update([
                                'extraction_status' => 'failed',
                                'extraction_error' => 'Erreur chunking: ' . $e->getMessage(),
                            ]);

                            Notification::make()
                                ->title('Erreur de chunking')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }
                }),

            Actions\Action::make('enrich_llm')
                ->label('Enrichir LLM')
                ->icon('heroicon-o-sparkles')
                ->color('purple')
                ->visible(fn () => $this->record->chunk_count > 0 && $this->record->chunk_strategy !== 'llm_assisted')
                ->requiresConfirmation()
                ->modalHeading('Enrichir les chunks avec le LLM')
                ->modalDescription('Le LLM va analyser chaque chunk pour ajouter : catégorie, mots-clés et résumé. Le contenu ne sera pas modifié.')
                ->modalIcon('heroicon-o-sparkles')
                ->action(function () {
                    EnrichChunksWithLlmJob::dispatch($this->record, batchSize: 10, reindexAfter: true);

                    Notification::make()
                        ->title('Enrichissement LLM lancé')
                        ->body('Les chunks seront enrichis avec catégories, keywords et résumés. Consultez la queue llm-chunking.')
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
