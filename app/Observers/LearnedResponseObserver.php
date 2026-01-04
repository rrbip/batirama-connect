<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LearnedResponse;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LearnedResponseObserver
{
    public const COLLECTION = 'learned_responses';

    public function __construct(
        private QdrantService $qdrantService,
        private EmbeddingService $embeddingService
    ) {}

    /**
     * Handle the LearnedResponse "created" event.
     * Indexe la nouvelle réponse dans Qdrant.
     */
    public function created(LearnedResponse $learnedResponse): void
    {
        $this->ensureCollectionExists();

        try {
            // Générer l'embedding de la question
            $vector = $this->embeddingService->embed($learnedResponse->question);

            // Générer un UUID pour Qdrant
            $pointId = Str::uuid()->toString();

            // Indexer dans Qdrant
            $success = $this->qdrantService->upsert(self::COLLECTION, [
                [
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => $learnedResponse->toQdrantPayload(),
                ]
            ]);

            if ($success) {
                // Sauvegarder le point ID sans déclencher l'observer à nouveau
                LearnedResponse::withoutEvents(function () use ($learnedResponse, $pointId) {
                    $learnedResponse->update(['qdrant_point_id' => $pointId]);
                });

                Log::info('LearnedResponse indexed in Qdrant', [
                    'learned_response_id' => $learnedResponse->id,
                    'qdrant_point_id' => $pointId,
                    'agent_id' => $learnedResponse->agent_id,
                ]);

                // Double indexation dans la collection de l'agent (si configurée)
                $this->indexInAgentCollection($learnedResponse, $vector);
            } else {
                Log::error('Failed to index LearnedResponse in Qdrant', [
                    'learned_response_id' => $learnedResponse->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error indexing LearnedResponse in Qdrant', [
                'learned_response_id' => $learnedResponse->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the LearnedResponse "updated" event.
     * Met à jour l'index Qdrant si nécessaire.
     */
    public function updated(LearnedResponse $learnedResponse): void
    {
        // Si pas de point ID, c'est une mise à jour interne (comme le stockage du point ID)
        if (empty($learnedResponse->qdrant_point_id)) {
            return;
        }

        try {
            // Si la question a changé, on doit régénérer l'embedding
            if ($learnedResponse->isDirty('question')) {
                $vector = $this->embeddingService->embed($learnedResponse->question);

                // Supprimer l'ancien point et créer un nouveau
                $this->deleteFromQdrant($learnedResponse);

                $newPointId = Str::uuid()->toString();

                $success = $this->qdrantService->upsert(self::COLLECTION, [
                    [
                        'id' => $newPointId,
                        'vector' => $vector,
                        'payload' => $learnedResponse->toQdrantPayload(),
                    ]
                ]);

                if ($success) {
                    LearnedResponse::withoutEvents(function () use ($learnedResponse, $newPointId) {
                        $learnedResponse->update(['qdrant_point_id' => $newPointId]);
                    });

                    Log::info('LearnedResponse re-indexed in Qdrant (question changed)', [
                        'learned_response_id' => $learnedResponse->id,
                        'new_qdrant_point_id' => $newPointId,
                    ]);

                    // Re-indexer dans la collection de l'agent
                    $this->indexInAgentCollection($learnedResponse, $vector);
                }
            } else {
                // Sinon, on met juste à jour le payload (compteurs, answer, etc.)
                $this->updatePayloadInQdrant($learnedResponse);
            }
        } catch (\Exception $e) {
            Log::error('Error updating LearnedResponse in Qdrant', [
                'learned_response_id' => $learnedResponse->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the LearnedResponse "deleted" event.
     * Supprime de Qdrant.
     */
    public function deleted(LearnedResponse $learnedResponse): void
    {
        $this->deleteFromQdrant($learnedResponse);
        $this->deleteFromAgentCollection($learnedResponse);
    }

    /**
     * Supprime le point de Qdrant learned_responses
     */
    private function deleteFromQdrant(LearnedResponse $learnedResponse): void
    {
        if (empty($learnedResponse->qdrant_point_id)) {
            return;
        }

        try {
            $success = $this->qdrantService->delete(self::COLLECTION, [$learnedResponse->qdrant_point_id]);

            if ($success) {
                Log::info('LearnedResponse removed from Qdrant', [
                    'learned_response_id' => $learnedResponse->id,
                    'qdrant_point_id' => $learnedResponse->qdrant_point_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete LearnedResponse from Qdrant', [
                'learned_response_id' => $learnedResponse->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Met à jour uniquement le payload dans Qdrant (sans changer le vecteur)
     */
    private function updatePayloadInQdrant(LearnedResponse $learnedResponse): void
    {
        if (empty($learnedResponse->qdrant_point_id)) {
            return;
        }

        try {
            // Qdrant permet de mettre à jour le payload sans toucher au vecteur
            $success = $this->qdrantService->updatePayload(
                self::COLLECTION,
                [$learnedResponse->qdrant_point_id],
                $learnedResponse->toQdrantPayload()
            );

            if ($success) {
                Log::debug('LearnedResponse payload updated in Qdrant', [
                    'learned_response_id' => $learnedResponse->id,
                ]);
            }
        } catch (\Exception $e) {
            // Si updatePayload n'existe pas, on fait un upsert complet
            Log::debug('Falling back to full re-index for payload update', [
                'learned_response_id' => $learnedResponse->id,
            ]);

            $vector = $this->embeddingService->embed($learnedResponse->question);
            $this->qdrantService->upsert(self::COLLECTION, [
                [
                    'id' => $learnedResponse->qdrant_point_id,
                    'vector' => $vector,
                    'payload' => $learnedResponse->toQdrantPayload(),
                ]
            ]);
        }
    }

    /**
     * Indexe aussi dans la collection de l'agent (pour le RAG normal)
     */
    private function indexInAgentCollection(LearnedResponse $learnedResponse, array $vector): void
    {
        $agent = $learnedResponse->agent;

        if (!$agent || empty($agent->qdrant_collection)) {
            return;
        }

        try {
            $pointId = 'lr_' . $learnedResponse->id; // Préfixe pour identifier les learned responses

            $this->qdrantService->upsert($agent->qdrant_collection, [
                [
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => [
                        'type' => 'qa_pair',
                        'learned_response_id' => $learnedResponse->id,
                        'question' => $learnedResponse->question,
                        'display_text' => $learnedResponse->answer,
                        'content' => "Q: {$learnedResponse->question}\nR: {$learnedResponse->answer}",
                        'requires_handoff' => $learnedResponse->requires_handoff,
                        'is_faq' => true,
                        'validation_count' => $learnedResponse->validation_count,
                        'rejection_count' => $learnedResponse->rejection_count,
                    ],
                ]
            ]);

            Log::debug('LearnedResponse indexed in agent collection', [
                'learned_response_id' => $learnedResponse->id,
                'collection' => $agent->qdrant_collection,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to index LearnedResponse in agent collection', [
                'learned_response_id' => $learnedResponse->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Supprime de la collection de l'agent
     */
    private function deleteFromAgentCollection(LearnedResponse $learnedResponse): void
    {
        $agent = $learnedResponse->agent;

        if (!$agent || empty($agent->qdrant_collection)) {
            return;
        }

        try {
            $pointId = 'lr_' . $learnedResponse->id;
            $this->qdrantService->delete($agent->qdrant_collection, [$pointId]);

            Log::debug('LearnedResponse removed from agent collection', [
                'learned_response_id' => $learnedResponse->id,
                'collection' => $agent->qdrant_collection,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to delete LearnedResponse from agent collection', [
                'learned_response_id' => $learnedResponse->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * S'assure que la collection learned_responses existe
     */
    private function ensureCollectionExists(): void
    {
        if ($this->qdrantService->collectionExists(self::COLLECTION)) {
            return;
        }

        $config = config('qdrant.collections.' . self::COLLECTION, [
            'vector_size' => config('ai.qdrant.vector_size', 768),
            'distance' => 'Cosine',
        ]);

        $created = $this->qdrantService->createCollection(self::COLLECTION, $config);

        if ($created) {
            Log::info('Collection learned_responses créée automatiquement par Observer');
        }
    }
}
