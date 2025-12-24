<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Document;
use App\Services\AI\QdrantService;
use Illuminate\Support\Facades\Log;

class DocumentObserver
{
    public function __construct(
        private QdrantService $qdrantService
    ) {}

    /**
     * Handle the Document "deleting" event.
     * Desindexe le document de Qdrant avant la suppression.
     */
    public function deleting(Document $document): void
    {
        $this->removeFromQdrant($document);
    }

    /**
     * Handle the Document "force deleting" event.
     * Desindexe le document de Qdrant avant la suppression definitive.
     */
    public function forceDeleting(Document $document): void
    {
        $this->removeFromQdrant($document);
    }

    /**
     * Supprime tous les points du document de Qdrant
     */
    private function removeFromQdrant(Document $document): void
    {
        // Verifier que le document a un agent avec une collection
        $agent = $document->agent;
        if (!$agent || empty($agent->qdrant_collection)) {
            return;
        }

        $collection = $agent->qdrant_collection;

        // Verifier que la collection existe
        if (!$this->qdrantService->collectionExists($collection)) {
            return;
        }

        try {
            // Recuperer les IDs des chunks indexes
            $chunkPointIds = $document->chunks()
                ->whereNotNull('qdrant_point_id')
                ->pluck('qdrant_point_id')
                ->toArray();

            if (empty($chunkPointIds)) {
                Log::info('Document has no indexed chunks to remove', [
                    'document_id' => $document->id,
                    'collection' => $collection,
                ]);
                return;
            }

            // Supprimer les points de Qdrant
            $success = $this->qdrantService->delete($collection, $chunkPointIds);

            if ($success) {
                Log::info('Document chunks removed from Qdrant', [
                    'document_id' => $document->id,
                    'collection' => $collection,
                    'points_removed' => count($chunkPointIds),
                ]);
            } else {
                Log::error('Failed to remove document chunks from Qdrant', [
                    'document_id' => $document->id,
                    'collection' => $collection,
                    'point_ids' => $chunkPointIds,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error removing document from Qdrant', [
                'document_id' => $document->id,
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
