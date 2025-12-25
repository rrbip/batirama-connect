<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use App\Services\DocumentExtractorService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Ramsey\Uuid\Uuid;

class ManageChunks extends Page
{
    protected static string $resource = DocumentResource::class;

    protected static string $view = 'filament.resources.document-resource.pages.manage-chunks';

    public ?Document $record = null;

    public array $selectedChunks = [];

    public ?int $editingChunkId = null;

    public string $editingContent = '';

    public function mount(int|string|Document $record): void
    {
        if ($record instanceof Document) {
            $this->record = $record->load('chunks');
        } else {
            $this->record = Document::with('chunks')->findOrFail($record);
        }
    }

    public function getTitle(): string
    {
        return "Gestion des chunks - {$this->record->title}";
    }

    public function getBreadcrumb(): string
    {
        return 'Chunks';
    }

    #[Computed]
    public function chunks(): Collection
    {
        return $this->record->chunks()->orderBy('chunk_index')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Retour au document')
                ->icon('heroicon-o-arrow-left')
                ->url(DocumentResource::getUrl('edit', ['record' => $this->record])),

            Actions\Action::make('mergeSelected')
                ->label('Fusionner sélection')
                ->icon('heroicon-o-link')
                ->color('warning')
                ->visible(fn () => count($this->selectedChunks) >= 2)
                ->requiresConfirmation()
                ->modalHeading('Fusionner les chunks sélectionnés')
                ->modalDescription(fn () => sprintf(
                    'Les %d chunks sélectionnés seront fusionnés dans l\'ordre de leur index. Cette action est irréversible.',
                    count($this->selectedChunks)
                ))
                ->action(fn () => $this->mergeSelectedChunks()),

            Actions\Action::make('reindexAll')
                ->label('Ré-indexer tout')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->action(fn () => $this->reindexAllChunks()),
        ];
    }

    /**
     * Toggle chunk selection
     */
    public function toggleChunkSelection(int $chunkId): void
    {
        if (in_array($chunkId, $this->selectedChunks)) {
            $this->selectedChunks = array_values(array_diff($this->selectedChunks, [$chunkId]));
        } else {
            $this->selectedChunks[] = $chunkId;
        }
    }

    /**
     * Select all chunks
     */
    public function selectAllChunks(): void
    {
        $this->selectedChunks = $this->chunks->pluck('id')->toArray();
    }

    /**
     * Deselect all chunks
     */
    public function deselectAllChunks(): void
    {
        $this->selectedChunks = [];
    }

    /**
     * Start editing a chunk
     */
    public function startEditing(int $chunkId): void
    {
        $chunk = DocumentChunk::find($chunkId);
        if ($chunk) {
            $this->editingChunkId = $chunkId;
            $this->editingContent = $chunk->content;
        }
    }

    /**
     * Cancel editing
     */
    public function cancelEditing(): void
    {
        $this->editingChunkId = null;
        $this->editingContent = '';
    }

    /**
     * Save chunk edit
     */
    public function saveChunkEdit(): void
    {
        if (!$this->editingChunkId) {
            return;
        }

        $chunk = DocumentChunk::find($this->editingChunkId);
        if (!$chunk) {
            $this->cancelEditing();
            return;
        }

        // Update chunk content
        $extractor = app(DocumentExtractorService::class);
        $newTokenCount = $extractor->estimateTokenCount($this->editingContent);

        $chunk->update([
            'content' => $this->editingContent,
            'content_hash' => md5($this->editingContent),
            'token_count' => $newTokenCount,
            'is_indexed' => false, // Mark as needing re-indexation
            'indexed_at' => null,
        ]);

        Log::info('Chunk content updated', [
            'chunk_id' => $chunk->id,
            'document_id' => $this->record->id,
            'new_token_count' => $newTokenCount,
        ]);

        Notification::make()
            ->title('Chunk mis à jour')
            ->body('Le contenu du chunk a été modifié. Pensez à ré-indexer.')
            ->success()
            ->send();

        $this->cancelEditing();
        unset($this->chunks);
    }

    /**
     * Delete a single chunk
     */
    public function deleteChunk(int $chunkId): void
    {
        $chunk = DocumentChunk::find($chunkId);
        if (!$chunk) {
            return;
        }

        // Delete from Qdrant if indexed
        if ($chunk->is_indexed && $chunk->qdrant_point_id) {
            try {
                $qdrant = app(QdrantService::class);
                $collection = $this->record->agent?->qdrant_collection;

                if ($collection) {
                    $qdrant->delete($collection, [$chunk->qdrant_point_id]);
                    Log::info('Chunk vector deleted from Qdrant', [
                        'chunk_id' => $chunk->id,
                        'point_id' => $chunk->qdrant_point_id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to delete chunk vector from Qdrant', [
                    'chunk_id' => $chunk->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $deletedIndex = $chunk->chunk_index;
        $chunk->delete();

        // Recalculate indexes for remaining chunks
        $this->reorderChunks($deletedIndex);

        // Update document chunk count
        $this->record->update(['chunk_count' => $this->record->chunks()->count()]);

        Notification::make()
            ->title('Chunk supprimé')
            ->success()
            ->send();

        // Remove from selection if was selected
        $this->selectedChunks = array_values(array_diff($this->selectedChunks, [$chunkId]));

        unset($this->chunks);
    }

    /**
     * Merge selected chunks
     */
    public function mergeSelectedChunks(): void
    {
        if (count($this->selectedChunks) < 2) {
            Notification::make()
                ->title('Erreur')
                ->body('Sélectionnez au moins 2 chunks à fusionner.')
                ->danger()
                ->send();
            return;
        }

        // Get chunks sorted by index
        $chunksToMerge = DocumentChunk::whereIn('id', $this->selectedChunks)
            ->orderBy('chunk_index')
            ->get();

        if ($chunksToMerge->count() < 2) {
            return;
        }

        // Merge content (lowest index first)
        $mergedContent = $chunksToMerge->pluck('content')->join("\n\n");

        // Keep the first chunk, delete others
        $firstChunk = $chunksToMerge->first();
        $chunksToDelete = $chunksToMerge->slice(1);

        // Delete vectors from Qdrant for chunks being removed
        $qdrant = app(QdrantService::class);
        $collection = $this->record->agent?->qdrant_collection;

        if ($collection) {
            $pointsToDelete = $chunksToDelete
                ->filter(fn ($c) => $c->is_indexed && $c->qdrant_point_id)
                ->pluck('qdrant_point_id')
                ->toArray();

            if (!empty($pointsToDelete)) {
                try {
                    $qdrant->delete($collection, $pointsToDelete);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete merged chunk vectors', ['error' => $e->getMessage()]);
                }
            }
        }

        // Delete the other chunks
        DocumentChunk::whereIn('id', $chunksToDelete->pluck('id'))->delete();

        // Update first chunk with merged content
        $extractor = app(DocumentExtractorService::class);
        $firstChunk->update([
            'content' => $mergedContent,
            'content_hash' => md5($mergedContent),
            'token_count' => $extractor->estimateTokenCount($mergedContent),
            'is_indexed' => false,
            'indexed_at' => null,
        ]);

        // Reorder remaining chunks
        $this->reorderAllChunks();

        // Update document chunk count
        $this->record->update(['chunk_count' => $this->record->chunks()->count()]);

        $this->selectedChunks = [];

        Notification::make()
            ->title('Chunks fusionnés')
            ->body(sprintf('%d chunks ont été fusionnés. Pensez à ré-indexer.', $chunksToMerge->count()))
            ->success()
            ->send();

        unset($this->chunks);
    }

    /**
     * Reorder chunks after deletion
     */
    private function reorderChunks(int $deletedIndex): void
    {
        DocumentChunk::where('document_id', $this->record->id)
            ->where('chunk_index', '>', $deletedIndex)
            ->decrement('chunk_index');
    }

    /**
     * Reorder all chunks sequentially
     */
    private function reorderAllChunks(): void
    {
        $chunks = $this->record->chunks()->orderBy('chunk_index')->get();

        foreach ($chunks as $index => $chunk) {
            if ($chunk->chunk_index !== $index) {
                $chunk->update(['chunk_index' => $index]);
            }
        }
    }

    /**
     * Re-index all chunks
     */
    public function reindexAllChunks(): void
    {
        $qdrant = app(QdrantService::class);
        $embedding = app(EmbeddingService::class);
        $collection = $this->record->agent?->qdrant_collection;

        if (!$collection) {
            Notification::make()
                ->title('Erreur')
                ->body('L\'agent n\'a pas de collection Qdrant configurée.')
                ->danger()
                ->send();
            return;
        }

        $indexed = 0;
        $failed = 0;

        foreach ($this->chunks as $chunk) {
            try {
                // Generate embedding
                $vector = $embedding->embed($chunk->content);

                // Generate deterministic point ID
                $pointId = Uuid::uuid5(
                    Uuid::NAMESPACE_DNS,
                    "chunk-{$this->record->id}-{$chunk->chunk_index}"
                )->toString();

                // Upsert to Qdrant
                $qdrant->upsert($collection, [[
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => [
                        'document_id' => $this->record->id,
                        'chunk_index' => $chunk->chunk_index,
                        'content' => $chunk->content,
                        'document_title' => $this->record->title ?? $this->record->original_name,
                        'category' => $this->record->category,
                        'agent_slug' => $this->record->agent?->slug,
                    ],
                ]]);

                // Update chunk
                $chunk->update([
                    'qdrant_point_id' => $pointId,
                    'is_indexed' => true,
                    'indexed_at' => now(),
                ]);

                $indexed++;

            } catch (\Exception $e) {
                Log::error('Failed to index chunk', [
                    'chunk_id' => $chunk->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        // Update document indexed status
        $this->record->update([
            'is_indexed' => $failed === 0,
            'indexed_at' => $failed === 0 ? now() : null,
        ]);

        Notification::make()
            ->title('Indexation terminée')
            ->body(sprintf('%d chunks indexés, %d échecs.', $indexed, $failed))
            ->success()
            ->send();

        unset($this->chunks);
    }

    /**
     * Re-index a single chunk
     */
    public function reindexChunk(int $chunkId): void
    {
        $chunk = DocumentChunk::find($chunkId);
        if (!$chunk) {
            return;
        }

        $qdrant = app(QdrantService::class);
        $embedding = app(EmbeddingService::class);
        $collection = $this->record->agent?->qdrant_collection;

        if (!$collection) {
            Notification::make()
                ->title('Erreur')
                ->body('L\'agent n\'a pas de collection Qdrant configurée.')
                ->danger()
                ->send();
            return;
        }

        try {
            // Generate embedding
            $vector = $embedding->embed($chunk->content);

            // Generate deterministic point ID
            $pointId = Uuid::uuid5(
                Uuid::NAMESPACE_DNS,
                "chunk-{$this->record->id}-{$chunk->chunk_index}"
            )->toString();

            // Upsert to Qdrant
            $qdrant->upsert($collection, [[
                'id' => $pointId,
                'vector' => $vector,
                'payload' => [
                    'document_id' => $this->record->id,
                    'chunk_index' => $chunk->chunk_index,
                    'content' => $chunk->content,
                    'document_title' => $this->record->title ?? $this->record->original_name,
                    'category' => $this->record->category,
                    'agent_slug' => $this->record->agent?->slug,
                ],
            ]]);

            // Update chunk
            $chunk->update([
                'qdrant_point_id' => $pointId,
                'is_indexed' => true,
                'indexed_at' => now(),
            ]);

            Notification::make()
                ->title('Chunk indexé')
                ->success()
                ->send();

        } catch (\Exception $e) {
            Log::error('Failed to index chunk', [
                'chunk_id' => $chunk->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Erreur d\'indexation')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        unset($this->chunks);
    }
}
