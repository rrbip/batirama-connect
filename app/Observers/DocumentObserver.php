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
     * Handle the Document "updating" event.
     * Supprime de Qdrant si le document va être re-traité ou change d'agent.
     */
    public function updating(Document $document): void
    {
        // Si extraction_status passe à 'pending', supprimer l'ancien index
        if ($document->isDirty('extraction_status') && $document->extraction_status === 'pending') {
            Log::info('Document being re-processed, removing old index', [
                'document_id' => $document->id,
            ]);
            $this->removeFromQdrant($document);
        }

        // Si l'agent change, supprimer de l'ancienne collection
        if ($document->isDirty('agent_id')) {
            $originalAgentId = $document->getOriginal('agent_id');

            if ($originalAgentId) {
                $oldAgent = \App\Models\Agent::find($originalAgentId);

                if ($oldAgent && !empty($oldAgent->qdrant_collection)) {
                    Log::info('Document agent changed, removing from old collection', [
                        'document_id' => $document->id,
                        'old_agent_id' => $originalAgentId,
                        'new_agent_id' => $document->agent_id,
                        'old_collection' => $oldAgent->qdrant_collection,
                    ]);

                    $this->removeFromQdrantCollection($document, $oldAgent->qdrant_collection);
                }
            }
        }
    }

    /**
     * Handle the Document "deleting" event.
     * Desindexe le document de Qdrant et supprime les chunks.
     */
    public function deleting(Document $document): void
    {
        // 1. Supprimer de Qdrant (avant de supprimer les chunks!)
        $this->removeFromQdrant($document);

        // 2. Supprimer les chunks de la DB
        $document->chunks()->delete();
    }

    /**
     * Handle the Document "force deleting" event.
     * Desindexe le document de Qdrant et supprime les chunks.
     */
    public function forceDeleting(Document $document): void
    {
        // 1. Supprimer de Qdrant (avant de supprimer les chunks!)
        $this->removeFromQdrant($document);

        // 2. Supprimer les chunks de la DB
        $document->chunks()->delete();
    }

    /**
     * Supprime tous les points du document de Qdrant (collection de l'agent actuel)
     */
    private function removeFromQdrant(Document $document): void
    {
        // Verifier que le document a un agent avec une collection
        $agent = $document->agent;
        if (!$agent || empty($agent->qdrant_collection)) {
            return;
        }

        $this->removeFromQdrantCollection($document, $agent->qdrant_collection);
    }

    /**
     * Supprime tous les points du document d'une collection Qdrant spécifique
     */
    private function removeFromQdrantCollection(Document $document, string $collection): void
    {
        // Verifier que la collection existe
        if (!$this->qdrantService->collectionExists($collection)) {
            return;
        }

        try {
            // Recuperer les IDs des chunks indexes (format ancien et nouveau)
            $allPointIds = [];

            foreach ($document->chunks()->get() as $chunk) {
                // Nouveau format: array de point IDs (Q/R Atomique)
                if (!empty($chunk->qdrant_point_ids) && is_array($chunk->qdrant_point_ids)) {
                    $allPointIds = array_merge($allPointIds, $chunk->qdrant_point_ids);
                }

                // Ancien format: single point ID
                if (!empty($chunk->qdrant_point_id)) {
                    $allPointIds[] = $chunk->qdrant_point_id;
                }
            }

            $allPointIds = array_unique($allPointIds);

            if (empty($allPointIds)) {
                Log::info('Document has no indexed chunks to remove', [
                    'document_id' => $document->id,
                    'collection' => $collection,
                ]);
                return;
            }

            // Supprimer les points de Qdrant
            $success = $this->qdrantService->delete($collection, $allPointIds);

            if ($success) {
                Log::info('Document chunks removed from Qdrant', [
                    'document_id' => $document->id,
                    'collection' => $collection,
                    'points_removed' => count($allPointIds),
                ]);
            } else {
                Log::error('Failed to remove document chunks from Qdrant', [
                    'document_id' => $document->id,
                    'collection' => $collection,
                    'point_count' => count($allPointIds),
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
