<?php

declare(strict_types=1);

namespace App\Services\AI;

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
        ?string $correctedContent = null
    ): bool {
        // Le message doit être une réponse assistant
        if ($message->role !== 'assistant') {
            return false;
        }

        // Récupérer la question originale
        $questionMessage = $message->session->messages()
            ->where('role', 'user')
            ->where('created_at', '<', $message->created_at)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$questionMessage) {
            Log::warning('No question found for learning', ['message_id' => $message->id]);
            return false;
        }

        // Contenu à apprendre
        $answerContent = $correctedContent ?? $message->content;
        $question = $questionMessage->content;

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
            validatorId: $validator->id
        );
    }

    /**
     * Indexe une réponse validée dans Qdrant
     */
    public function indexLearnedResponse(
        string $question,
        string $answer,
        int $agentId,
        string $agentSlug,
        int $messageId,
        int $validatorId
    ): bool {
        // S'assurer que la collection existe
        $this->ensureCollectionExists();

        // Générer l'embedding de la question
        $vector = $this->embeddingService->embed($question);

        $pointId = Str::uuid()->toString();

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
                ],
            ]
        ]);

        if ($result) {
            Log::info('Learned response indexed', [
                'message_id' => $messageId,
                'agent' => $agentSlug,
            ]);
        } else {
            Log::error('Failed to upsert learned response to Qdrant', [
                'message_id' => $messageId,
                'agent' => $agentSlug,
            ]);
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
     * Valide un message sans apprentissage
     */
    public function validate(AiMessage $message, int $validatorId): bool
    {
        return $message->update([
            'validation_status' => 'validated',
            'validated_by' => $validatorId,
            'validated_at' => now(),
        ]);
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
    public function learn(AiMessage $message, string $correctedContent, int $validatorId): bool
    {
        $validator = User::find($validatorId);
        if (!$validator) {
            return false;
        }

        return $this->validateAndLearn($message, $validator, $correctedContent);
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
