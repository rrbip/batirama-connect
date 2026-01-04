<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\LearnedResponse;
use App\Services\AI\QdrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AgentResetService
{
    public function __construct(
        private QdrantService $qdrantService
    ) {}

    /**
     * Reinitialise completement un agent:
     * - Supprime toutes les sessions IA et leurs messages (incluant rag_context)
     * - Supprime tous les documents et leurs chunks
     * - Supprime et recree la collection Qdrant
     * - Supprime les reponses apprises de l'agent
     */
    public function reset(Agent $agent): array
    {
        $stats = [
            'sessions_deleted' => 0,
            'messages_deleted' => 0,
            'documents_deleted' => 0,
            'chunks_deleted' => 0,
            'files_deleted' => 0,
            'collection_reset' => false,
            'learned_responses_deleted' => 0,
        ];

        Log::info('Starting agent reset', [
            'agent_id' => $agent->id,
            'agent_slug' => $agent->slug,
        ]);

        DB::beginTransaction();

        try {
            // 1. Supprimer les sessions IA et leurs messages (contient rag_context)
            $sessionStats = $this->deleteSessions($agent);
            $stats['sessions_deleted'] = $sessionStats['sessions'];
            $stats['messages_deleted'] = $sessionStats['messages'];

            // 2. Supprimer les fichiers physiques
            $documents = $agent->documents()->get();
            foreach ($documents as $document) {
                if ($document->storage_path && Storage::disk('local')->exists($document->storage_path)) {
                    Storage::disk('local')->delete($document->storage_path);
                    $stats['files_deleted']++;
                }
            }

            // 3. Supprimer les chunks (en cascade depuis les documents)
            $stats['chunks_deleted'] = DB::table('document_chunks')
                ->whereIn('document_id', $agent->documents()->pluck('id'))
                ->delete();

            // 4. Supprimer les documents (force delete pour eviter soft delete)
            $stats['documents_deleted'] = $agent->documents()->forceDelete();

            // 5. Reinitialiser la collection Qdrant de l'agent
            if (!empty($agent->qdrant_collection)) {
                $stats['collection_reset'] = $this->resetQdrantCollection($agent);
            }

            // 6. Supprimer les reponses apprises pour cet agent
            $stats['learned_responses_deleted'] = $this->deleteLearnedResponses($agent);

            DB::commit();

            Log::info('Agent reset completed', [
                'agent_id' => $agent->id,
                'agent_slug' => $agent->slug,
                'stats' => $stats,
            ]);

            return $stats;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Agent reset failed', [
                'agent_id' => $agent->id,
                'agent_slug' => $agent->slug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Supprime toutes les sessions IA et leurs messages associes
     */
    private function deleteSessions(Agent $agent): array
    {
        $sessionIds = $agent->sessions()->pluck('id');

        if ($sessionIds->isEmpty()) {
            return ['sessions' => 0, 'messages' => 0];
        }

        // Supprimer les feedbacks des messages
        DB::table('ai_feedbacks')
            ->whereIn('message_id', function ($query) use ($sessionIds) {
                $query->select('id')
                    ->from('ai_messages')
                    ->whereIn('session_id', $sessionIds);
            })
            ->delete();

        // Supprimer les messages (contient rag_context avec les donnees envoyees a l'IA)
        $messagesDeleted = DB::table('ai_messages')
            ->whereIn('session_id', $sessionIds)
            ->delete();

        // Supprimer les tokens d'acces public lies aux sessions
        DB::table('public_access_tokens')
            ->whereIn('session_id', $sessionIds)
            ->delete();

        // Supprimer les sessions
        $sessionsDeleted = $agent->sessions()->delete();

        Log::info('Sessions and messages deleted', [
            'agent_id' => $agent->id,
            'sessions' => $sessionsDeleted,
            'messages' => $messagesDeleted,
        ]);

        return [
            'sessions' => $sessionsDeleted,
            'messages' => $messagesDeleted,
        ];
    }

    /**
     * Supprime et recree la collection Qdrant de l'agent
     */
    private function resetQdrantCollection(Agent $agent): bool
    {
        $collection = $agent->qdrant_collection;

        if (empty($collection)) {
            return false;
        }

        try {
            // Supprimer la collection si elle existe
            if ($this->qdrantService->collectionExists($collection)) {
                $this->qdrantService->deleteCollection($collection);
                Log::info('Qdrant collection deleted', [
                    'collection' => $collection,
                ]);
            }

            // Recreer la collection
            $config = config("qdrant.collections.{$collection}", [
                'vector_size' => config('ai.qdrant.vector_size', 768),
                'distance' => config('ai.qdrant.distance', 'Cosine'),
            ]);

            $created = $this->qdrantService->createCollection($collection, $config);

            if ($created) {
                Log::info('Qdrant collection recreated', [
                    'collection' => $collection,
                ]);

                // Recreer les index sur les champs payload
                $indexes = $config['payload_indexes'] ?? [
                    'document_id' => 'integer',
                    'document_uuid' => 'keyword',
                    'category' => 'keyword',
                    'source_type' => 'keyword',
                ];

                foreach ($indexes as $field => $type) {
                    $this->qdrantService->createPayloadIndex($collection, $field, $type);
                }
            }

            return $created;

        } catch (\Exception $e) {
            Log::error('Failed to reset Qdrant collection', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Supprime les reponses apprises pour cet agent.
     * - Supprime de la table PostgreSQL learned_responses (l'Observer supprime automatiquement de Qdrant)
     * - Nettoie aussi directement dans Qdrant au cas où il y aurait des orphelins
     */
    private function deleteLearnedResponses(Agent $agent): int
    {
        $deletedCount = 0;

        try {
            // 1. Supprimer de la table PostgreSQL (l'Observer gère la suppression Qdrant)
            $deletedCount = LearnedResponse::where('agent_id', $agent->id)->delete();

            Log::info('Learned responses deleted from PostgreSQL', [
                'agent_id' => $agent->id,
                'agent_slug' => $agent->slug,
                'count' => $deletedCount,
            ]);

            // 2. Nettoyer aussi dans Qdrant (au cas où il y aurait des orphelins)
            $learnedCollection = 'learned_responses';
            if ($this->qdrantService->collectionExists($learnedCollection)) {
                $filter = [
                    'must' => [
                        ['key' => 'agent_slug', 'match' => ['value' => $agent->slug]]
                    ]
                ];
                $this->qdrantService->deleteByFilter($learnedCollection, $filter);

                Log::info('Learned responses cleaned from Qdrant collection', [
                    'agent_slug' => $agent->slug,
                    'collection' => $learnedCollection,
                ]);
            }

            // 3. Nettoyer les points FAQ dans la collection de l'agent
            if (!empty($agent->qdrant_collection) && $this->qdrantService->collectionExists($agent->qdrant_collection)) {
                $faqFilter = [
                    'must' => [
                        ['key' => 'type', 'match' => ['value' => 'qa_pair']],
                        ['key' => 'is_faq', 'match' => ['value' => true]],
                    ]
                ];
                $this->qdrantService->deleteByFilter($agent->qdrant_collection, $faqFilter);

                Log::info('FAQ points cleaned from agent collection', [
                    'agent_slug' => $agent->slug,
                    'collection' => $agent->qdrant_collection,
                ]);
            }

            return $deletedCount;

        } catch (\Exception $e) {
            Log::error('Failed to delete learned responses', [
                'agent_id' => $agent->id,
                'agent_slug' => $agent->slug,
                'error' => $e->getMessage(),
            ]);

            return $deletedCount;
        }
    }
}
