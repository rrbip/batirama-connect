<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LearningService
{
    private const LEARNED_RESPONSES_COLLECTION = 'learned_responses';

    public function __construct(
        private EmbeddingService $embeddingService,
        private QdrantService $qdrantService
    ) {}

    /**
     * Valide un message et l'ajoute à la base d'apprentissage
     */
    public function validateAndLearn(
        AiMessage $message,
        User $validator,
        ?string $correctedContent = null,
        ?string $customQuestion = null,
        bool $requiresHandoff = false
    ): bool {
        // Le message doit être une réponse assistant
        if ($message->role !== 'assistant') {
            return false;
        }

        // Récupérer la question originale (utiliser id car created_at peut être identique)
        $questionMessage = $message->session->messages()
            ->where('role', 'user')
            ->where('id', '<', $message->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$questionMessage) {
            throw new \RuntimeException("Aucune question utilisateur trouvée avant ce message assistant (ID: {$message->id})");
        }

        // Contenu à apprendre (question personnalisée ou originale)
        $answerContent = $correctedContent ?? $message->content;
        $question = $customQuestion ?? $questionMessage->content;

        // Mettre à jour le statut du message
        $message->update([
            'validation_status' => 'learned',
            'validated_by' => $validator->id,
            'validated_at' => now(),
            'corrected_content' => $correctedContent,
        ]);

        // Indexer dans la collection d'apprentissage
        return $this->indexLearnedResponse(
            question: $question,
            answer: $answerContent,
            agentId: $message->session->agent_id,
            agentSlug: $message->session->agent->slug,
            messageId: $message->id,
            validatorId: $validator->id,
            requiresHandoff: $requiresHandoff
        );
    }

    /**
     * Indexe une réponse validée dans Qdrant (learned_responses + collection agent)
     */
    public function indexLearnedResponse(
        string $question,
        string $answer,
        int $agentId,
        string $agentSlug,
        int $messageId,
        int $validatorId,
        bool $requiresHandoff = false
    ): bool {
        // S'assurer que la collection existe
        $this->ensureCollectionExists();

        // Supprimer les anciens points pour ce message_id (évite les doublons lors des corrections)
        $this->deleteExistingPointsForMessage($messageId, $agentId);

        // Générer l'embedding de la question
        $vector = $this->embeddingService->embed($question);

        $pointId = Str::uuid()->toString();

        // 1. Indexer dans learned_responses
        $result = $this->qdrantService->upsert(self::LEARNED_RESPONSES_COLLECTION, [
            [
                'id' => $pointId,
                'vector' => $vector,
                'payload' => [
                    'agent_id' => $agentId,
                    'agent_slug' => $agentSlug,
                    'message_id' => $messageId,
                    'question' => $question,
                    'answer' => $answer,
                    'validated_by' => $validatorId,
                    'validated_at' => now()->toIso8601String(),
                    'requires_handoff' => $requiresHandoff,
                ],
            ]
        ]);

        if ($result) {
            Log::info('Learned response indexed in learned_responses', [
                'message_id' => $messageId,
                'agent' => $agentSlug,
            ]);

            // 2. Double indexation : indexer aussi dans la collection de l'agent
            $agent = Agent::find($agentId);
            if ($agent) {
                $this->indexFaqInAgentCollection($agent, $question, $answer, $messageId, $requiresHandoff);
            }
        } else {
            Log::error('Failed to upsert learned response to Qdrant', [
                'message_id' => $messageId,
                'agent' => $agentSlug,
            ]);
        }

        return $result;
    }

    /**
     * Indexe une FAQ dans la collection de l'agent avec type=qa_pair.
     * Permet aux FAQ d'être trouvées lors des recherches RAG normales.
     */
    public function indexFaqInAgentCollection(
        Agent $agent,
        string $question,
        string $answer,
        ?int $messageId = null,
        bool $requiresHandoff = false
    ): ?string {
        if (empty($agent->qdrant_collection)) {
            Log::warning('LearningService: Agent has no Qdrant collection', [
                'agent' => $agent->slug,
            ]);
            return null;
        }

        $embedding = $this->embeddingService->embed($question);
        $pointId = Str::uuid()->toString();

        $result = $this->qdrantService->upsert($agent->qdrant_collection, [[
            'id' => $pointId,
            'vector' => $embedding,
            'payload' => [
                'type' => 'qa_pair',
                'category' => 'FAQ',
                'display_text' => $answer,
                'question' => $question,
                'source_doc' => 'FAQ Validée',
                'parent_context' => '',
                'chunk_id' => null,
                'document_id' => null,
                'agent_id' => $agent->id,
                'is_faq' => true,
                'message_id' => $messageId,
                'indexed_at' => now()->toIso8601String(),
                'requires_handoff' => $requiresHandoff,
            ],
        ]]);

        if ($result) {
            Log::info('LearningService: FAQ indexed in agent collection', [
                'agent' => $agent->slug,
                'collection' => $agent->qdrant_collection,
                'point_id' => $pointId,
                'message_id' => $messageId,
            ]);
            return $pointId;
        }

        Log::error('LearningService: Failed to index FAQ in agent collection', [
            'agent' => $agent->slug,
        ]);
        return null;
    }

    /**
     * Ajoute une FAQ manuelle (double indexation).
     */
    public function addManualFaq(
        Agent $agent,
        string $question,
        string $answer,
        int $userId,
        bool $requiresHandoff = false
    ): bool {
        // S'assurer que la collection learned_responses existe
        $this->ensureCollectionExists();

        // Générer l'embedding de la question
        $vector = $this->embeddingService->embed($question);
        $pointId = Str::uuid()->toString();

        // 1. Indexer dans learned_responses
        $result = $this->qdrantService->upsert(self::LEARNED_RESPONSES_COLLECTION, [
            [
                'id' => $pointId,
                'vector' => $vector,
                'payload' => [
                    'agent_id' => $agent->id,
                    'agent_slug' => $agent->slug,
                    'message_id' => null,
                    'question' => $question,
                    'answer' => $answer,
                    'validated_by' => $userId,
                    'validated_at' => now()->toIso8601String(),
                    'source' => 'manual',
                    'requires_handoff' => $requiresHandoff,
                ],
            ]
        ]);

        if ($result) {
            Log::info('Manual FAQ indexed in learned_responses', [
                'agent' => $agent->slug,
            ]);

            // 2. Double indexation : indexer aussi dans la collection de l'agent
            $this->indexFaqInAgentCollection($agent, $question, $answer, null, $requiresHandoff);
        }

        return $result;
    }

    /**
     * Recherche des réponses apprises similaires
     */
    public function findSimilarLearnedResponses(
        string $question,
        string $agentSlug,
        int $limit = 3,
        float $minScore = 0.85
    ): array {
        try {
            $vector = $this->embeddingService->embed($question);

            $results = $this->qdrantService->search(
                vector: $vector,
                collection: self::LEARNED_RESPONSES_COLLECTION,
                limit: $limit,
                filter: [
                    'must' => [
                        ['key' => 'agent_slug', 'match' => ['value' => $agentSlug]]
                    ]
                ],
                scoreThreshold: $minScore
            );

            return collect($results)->map(fn ($r) => [
                'question' => $r['payload']['question'] ?? '',
                'answer' => $r['payload']['answer'] ?? '',
                'score' => $r['score'],
                'message_id' => $r['payload']['message_id'] ?? null,
            ])->toArray();

        } catch (\Exception $e) {
            Log::error('Failed to search learned responses', [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Valide un message ET l'ajoute à la base d'apprentissage
     * (la réponse originale est considérée comme correcte)
     */
    public function validate(AiMessage $message, int $validatorId, ?string $customQuestion = null, bool $requiresHandoff = false): bool
    {
        // Si c'est un direct_qr_match avec une question modifiée ou un flag handoff, on ré-indexe
        if ($message->model_used === 'direct_qr_match' && empty($customQuestion) && !$requiresHandoff) {
            Log::info('Skip learning for direct_qr_match - already in learned_responses', [
                'message_id' => $message->id,
            ]);
            return $message->update([
                'validation_status' => 'validated',
                'validated_by' => $validatorId,
                'validated_at' => now(),
            ]);
        }

        $validator = User::find($validatorId);
        if (!$validator) {
            return $message->update([
                'validation_status' => 'validated',
                'validated_by' => $validatorId,
                'validated_at' => now(),
            ]);
        }

        // Valider ET apprendre (sans correction = réponse originale, avec question optionnelle)
        return $this->validateAndLearn($message, $validator, null, $customQuestion, $requiresHandoff);
    }

    /**
     * Rejette un message (pas d'apprentissage)
     */
    public function reject(AiMessage $message, int $validatorId, ?string $reason = null): bool
    {
        return $message->update([
            'validation_status' => 'rejected',
            'validated_by' => $validatorId,
            'validated_at' => now(),
        ]);
    }

    /**
     * Apprend d'un message corrigé
     */
    public function learn(AiMessage $message, string $correctedContent, int $validatorId, bool $requiresHandoff = false): bool
    {
        $validator = User::find($validatorId);
        if (!$validator) {
            return false;
        }

        return $this->validateAndLearn($message, $validator, $correctedContent, null, $requiresHandoff);
    }

    /**
     * Apprend d'un message avec question et réponse personnalisées
     */
    public function learnWithQuestion(
        AiMessage $message,
        string $customQuestion,
        string $correctedContent,
        int $validatorId,
        bool $requiresHandoff = false
    ): bool {
        $validator = User::find($validatorId);
        if (!$validator) {
            return false;
        }

        return $this->validateAndLearn($message, $validator, $correctedContent, $customQuestion, $requiresHandoff);
    }

    /**
     * Récupère les statistiques d'apprentissage
     */
    public function getStats(): array
    {
        $collection = self::LEARNED_RESPONSES_COLLECTION;

        if (!$this->qdrantService->collectionExists($collection)) {
            return [
                'total_learned' => 0,
                'by_agent' => [],
            ];
        }

        $total = $this->qdrantService->count($collection);

        // TODO: Agrégation par agent (nécessite scroll)

        return [
            'total_learned' => $total,
            'collection' => $collection,
        ];
    }

    /**
     * Supprime les points existants pour un message_id donné (évite les doublons lors des corrections)
     * Supprime à la fois dans learned_responses et dans la collection de l'agent
     */
    private function deleteExistingPointsForMessage(int $messageId, int $agentId): void
    {
        // Supprimer dans learned_responses
        $filter = [
            'must' => [
                ['key' => 'message_id', 'match' => ['value' => $messageId]]
            ]
        ];

        $deleted = $this->qdrantService->deleteByFilter(self::LEARNED_RESPONSES_COLLECTION, $filter);
        if ($deleted) {
            Log::info('Deleted existing learned_response point for message', ['message_id' => $messageId]);
        }

        // Supprimer dans la collection de l'agent (si elle existe)
        $agent = Agent::find($agentId);
        if ($agent && !empty($agent->qdrant_collection)) {
            $deleted = $this->qdrantService->deleteByFilter($agent->qdrant_collection, $filter);
            if ($deleted) {
                Log::info('Deleted existing FAQ point in agent collection', [
                    'message_id' => $messageId,
                    'collection' => $agent->qdrant_collection,
                ]);
            }
        }
    }

    /**
     * S'assure que la collection learned_responses existe
     */
    private function ensureCollectionExists(): void
    {
        if ($this->qdrantService->collectionExists(self::LEARNED_RESPONSES_COLLECTION)) {
            return;
        }

        $config = config('qdrant.collections.' . self::LEARNED_RESPONSES_COLLECTION, [
            'vector_size' => config('ai.qdrant.vector_size', 768),
            'distance' => 'Cosine',
        ]);

        $created = $this->qdrantService->createCollection(self::LEARNED_RESPONSES_COLLECTION, $config);

        if ($created) {
            Log::info('Collection learned_responses créée automatiquement');

            // Créer les index sur les champs payload
            $indexes = $config['payload_indexes'] ?? [];
            foreach ($indexes as $field => $type) {
                $this->qdrantService->createPayloadIndex(self::LEARNED_RESPONSES_COLLECTION, $field, $type);
            }
        } else {
            Log::error('Impossible de créer la collection learned_responses');
        }
    }
}
