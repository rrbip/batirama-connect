<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DocumentChunk;
use App\Services\AI\QdrantService;
use Illuminate\Support\Facades\Log;

/**
 * Observer pour DocumentChunk
 *
 * Gère automatiquement:
 * - La suppression des points Qdrant quand un chunk est supprimé
 * - La mise à jour des compteurs de catégories
 * - La suppression des catégories orphelines générées par l'IA
 */
class DocumentChunkObserver
{
    public function __construct(
        private QdrantService $qdrantService
    ) {}

    /**
     * Handle the DocumentChunk "deleting" event.
     * Supprime les points Qdrant associés avant la suppression du chunk.
     */
    public function deleting(DocumentChunk $chunk): void
    {
        $this->removeFromQdrant($chunk);
        $this->handleCategoryCleanup($chunk);
    }

    /**
     * Handle the DocumentChunk "force deleting" event.
     * Supprime les points Qdrant associés avant la suppression du chunk.
     */
    public function forceDeleting(DocumentChunk $chunk): void
    {
        $this->removeFromQdrant($chunk);
        $this->handleCategoryCleanup($chunk);
    }

    /**
     * Supprime les points Qdrant associés au chunk
     */
    private function removeFromQdrant(DocumentChunk $chunk): void
    {
        // Get the agent's collection
        $document = $chunk->document;
        if (!$document) {
            return;
        }

        $agent = $document->agent;
        if (!$agent || empty($agent->qdrant_collection)) {
            return;
        }

        $collection = $agent->qdrant_collection;

        // Check if collection exists
        if (!$this->qdrantService->collectionExists($collection)) {
            return;
        }

        // Collect point IDs to delete (handle both old and new format)
        $pointIds = $this->getPointIds($chunk);

        if (empty($pointIds)) {
            return;
        }

        try {
            $this->qdrantService->delete($collection, $pointIds);

            Log::debug('DocumentChunkObserver: Removed Qdrant points', [
                'chunk_id' => $chunk->id,
                'collection' => $collection,
                'points_removed' => count($pointIds),
            ]);
        } catch (\Exception $e) {
            // Log but don't fail - points might already be deleted
            Log::warning('DocumentChunkObserver: Failed to remove Qdrant points', [
                'chunk_id' => $chunk->id,
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gère le nettoyage de la catégorie quand un chunk est supprimé
     */
    private function handleCategoryCleanup(DocumentChunk $chunk): void
    {
        if (!$chunk->category_id) {
            return;
        }

        $category = $chunk->category;
        if (!$category) {
            return;
        }

        // Décrémente le compteur d'utilisation
        $category->decrementUsage();

        // Recharge pour avoir le compteur à jour
        $category->refresh();

        // Si la catégorie n'est plus utilisée ET est générée par l'IA, on la supprime
        if ($category->usage_count <= 0 && $category->is_ai_generated) {
            Log::info('DocumentChunkObserver: Deleting orphan AI-generated category', [
                'category_id' => $category->id,
                'category_name' => $category->name,
            ]);

            $category->delete();
        }
    }

    /**
     * Get all Qdrant point IDs from chunk (handles both old and new format)
     */
    private function getPointIds(DocumentChunk $chunk): array
    {
        $pointIds = [];

        // New format: array of point IDs (Q/R Atomique)
        if (!empty($chunk->qdrant_point_ids) && is_array($chunk->qdrant_point_ids)) {
            $pointIds = array_merge($pointIds, $chunk->qdrant_point_ids);
        }

        // Old format: single point ID
        if (!empty($chunk->qdrant_point_id)) {
            $pointIds[] = $chunk->qdrant_point_id;
        }

        return array_unique($pointIds);
    }
}
