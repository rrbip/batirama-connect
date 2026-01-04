<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\LearnedResponse;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class LearningService
{
    private const LEARNED_RESPONSES_COLLECTION = 'learned_responses';

    public function __construct(
        private EmbeddingService $embeddingService,
        private QdrantService $qdrantService
    ) {}

    /**
     * Valide un message et l'ajoute à la base d'apprentissage.
     * Si c'est un direct_qr_match, incrémente le compteur de validation de la LearnedResponse existante.
     */
    public function validateAndLearn(
        AiMessage $message,
        User $validator,
        ?string $correctedContent = null,
        ?string $customQuestion = null,
        bool $requiresHandoff = false,
        ?int $existingLearnedResponseId = null
    ): bool {
        // Le message doit être une réponse assistant
        if ($message->role !== 'assistant') {
            return false;
        }

        // Si on a un ID de LearnedResponse existant (direct_qr_match), on incrémente
        if ($existingLearnedResponseId) {
            return $this->incrementValidation($existingLearnedResponseId, $validator->id, $correctedContent, $requiresHandoff);
        }

        // Récupérer la question originale
        $questionMessage = $message->session->messages()
            ->where('role', 'user')
            ->where('id', '<', $message->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$questionMessage) {
            throw new \RuntimeException("Aucune question utilisateur trouvée avant ce message assistant (ID: {$message->id})");
        }

        // Contenu à apprendre
        $answerContent = $correctedContent ?? $message->content;
        $question = $customQuestion ?? $questionMessage->content;

        // Mettre à jour le statut du message
        $message->update([
            'validation_status' => 'learned',
            'validated_by' => $validator->id,
            'validated_at' => now(),
            'corrected_content' => $correctedContent,
        ]);

        // Créer ou mettre à jour la LearnedResponse
        return $this->createOrUpdateLearnedResponse(
            agentId: $message->session->agent_id,
            question: $question,
            answer: $answerContent,
            userId: $validator->id,
            sourceMessageId: $message->id,
            requiresHandoff: $requiresHandoff,
            source: LearnedResponse::SOURCE_AI_VALIDATION
        );
    }

    /**
     * Crée ou met à jour une LearnedResponse.
     * L'Observer gère automatiquement la synchronisation avec Qdrant.
     */
    public function createOrUpdateLearnedResponse(
        int $agentId,
        string $question,
        string $answer,
        int $userId,
        ?int $sourceMessageId = null,
        bool $requiresHandoff = false,
        string $source = LearnedResponse::SOURCE_AI_VALIDATION
    ): bool {
        try {
            // Unicité basée sur agent_id + question uniquement
            // Le source_message_id n'est qu'une métadonnée (pour traçabilité), pas un critère d'unicité
            $existing = LearnedResponse::where('agent_id', $agentId)
                ->where('question', $question)
                ->first();

            if ($existing) {
                // Mettre à jour l'existante
                $existing->update([
                    'answer' => $answer,
                    'requires_handoff' => $requiresHandoff,
                    'last_validated_by' => $userId,
                    'last_validated_at' => now(),
                ]);
                $existing->increment('validation_count');

                Log::info('LearnedResponse updated', [
                    'learned_response_id' => $existing->id,
                    'validation_count' => $existing->validation_count + 1,
                    'question' => \Illuminate\Support\Str::limit($question, 50),
                ]);
            } else {
                // Créer une nouvelle
                LearnedResponse::create([
                    'agent_id' => $agentId,
                    'question' => $question,
                    'answer' => $answer,
                    'validation_count' => 1,
                    'rejection_count' => 0,
                    'requires_handoff' => $requiresHandoff,
                    'source' => $source,
                    'source_message_id' => $sourceMessageId,
                    'created_by' => $userId,
                    'last_validated_by' => $userId,
                    'last_validated_at' => now(),
                ]);

                Log::info('LearnedResponse created', [
                    'agent_id' => $agentId,
                    'question' => \Illuminate\Support\Str::limit($question, 50),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to create/update LearnedResponse', [
                'error' => $e->getMessage(),
                'agent_id' => $agentId,
            ]);
            return false;
        }
    }

    /**
     * Incrémente le compteur de validation d'une LearnedResponse existante.
     * Utilisé quand on re-valide un direct_qr_match.
     */
    public function incrementValidation(
        int $learnedResponseId,
        int $userId,
        ?string $correctedAnswer = null,
        bool $requiresHandoff = false
    ): bool {
        try {
            $lr = LearnedResponse::find($learnedResponseId);
            if (!$lr) {
                Log::warning('LearnedResponse not found for increment', ['id' => $learnedResponseId]);
                return false;
            }

            $updateData = [
                'last_validated_by' => $userId,
                'last_validated_at' => now(),
            ];

            // Si la réponse a été corrigée, mettre à jour
            if ($correctedAnswer) {
                $updateData['answer'] = $correctedAnswer;
            }

            // Si le flag handoff a changé
            if ($requiresHandoff !== $lr->requires_handoff) {
                $updateData['requires_handoff'] = $requiresHandoff;
            }

            $lr->update($updateData);
            $lr->increment('validation_count');

            Log::info('LearnedResponse validation incremented', [
                'learned_response_id' => $learnedResponseId,
                'new_count' => $lr->validation_count,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to increment LearnedResponse validation', [
                'error' => $e->getMessage(),
                'learned_response_id' => $learnedResponseId,
            ]);
            return false;
        }
    }

    /**
     * Incrémente le compteur de rejet d'une LearnedResponse existante.
     * Utilisé quand on rejette un direct_qr_match.
     */
    public function incrementRejection(int $learnedResponseId): bool
    {
        try {
            $lr = LearnedResponse::find($learnedResponseId);
            if (!$lr) {
                Log::warning('LearnedResponse not found for rejection', ['id' => $learnedResponseId]);
                return false;
            }

            $lr->increment('rejection_count');

            Log::info('LearnedResponse rejection incremented', [
                'learned_response_id' => $learnedResponseId,
                'new_count' => $lr->rejection_count,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to increment LearnedResponse rejection', [
                'error' => $e->getMessage(),
                'learned_response_id' => $learnedResponseId,
            ]);
            return false;
        }
    }

    /**
     * Ajoute une FAQ manuelle.
     * L'Observer gère automatiquement la synchronisation avec Qdrant.
     */
    public function addManualFaq(
        Agent $agent,
        string $question,
        string $answer,
        int $userId,
        bool $requiresHandoff = false
    ): bool {
        return $this->createOrUpdateLearnedResponse(
            agentId: $agent->id,
            question: $question,
            answer: $answer,
            userId: $userId,
            sourceMessageId: null,
            requiresHandoff: $requiresHandoff,
            source: LearnedResponse::SOURCE_MANUAL
        );
    }

    /**
     * Recherche des réponses apprises similaires via Qdrant.
     * Retourne aussi le learned_response_id pour permettre les mises à jour.
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
                'qdrant_point_id' => $r['id'] ?? null,
                'learned_response_id' => $r['payload']['learned_response_id'] ?? null,
                'validation_count' => $r['payload']['validation_count'] ?? 1,
                'rejection_count' => $r['payload']['rejection_count'] ?? 0,
                'requires_handoff' => $r['payload']['requires_handoff'] ?? false,
                // Garder message_id pour compatibilité avec l'ancien système
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
     * Valide un message (sans correction).
     */
    public function validate(
        AiMessage $message,
        int $validatorId,
        ?string $customQuestion = null,
        bool $requiresHandoff = false,
        ?int $existingLearnedResponseId = null
    ): bool {
        // Si c'est un direct_qr_match avec un learned_response_id, on incrémente
        if ($existingLearnedResponseId) {
            $message->update([
                'validation_status' => 'validated',
                'validated_by' => $validatorId,
                'validated_at' => now(),
            ]);

            return $this->incrementValidation($existingLearnedResponseId, $validatorId, null, $requiresHandoff);
        }

        // Si c'est un direct_qr_match sans learned_response_id (ancien système), on skip
        if ($message->model_used === 'direct_qr_match' && empty($customQuestion) && !$requiresHandoff) {
            Log::info('Skip learning for legacy direct_qr_match', ['message_id' => $message->id]);
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

        return $this->validateAndLearn($message, $validator, null, $customQuestion, $requiresHandoff);
    }

    /**
     * Rejette un message.
     * Si c'est un direct_qr_match avec un learned_response_id, incrémente le compteur de rejet.
     */
    public function reject(
        AiMessage $message,
        int $validatorId,
        ?string $reason = null,
        ?int $existingLearnedResponseId = null
    ): bool {
        $message->update([
            'validation_status' => 'rejected',
            'validated_by' => $validatorId,
            'validated_at' => now(),
        ]);

        // Si c'est un direct_qr_match, incrémenter le compteur de rejet
        if ($existingLearnedResponseId) {
            $this->incrementRejection($existingLearnedResponseId);
        }

        return true;
    }

    /**
     * Apprend d'un message corrigé.
     */
    public function learn(
        AiMessage $message,
        string $correctedContent,
        int $validatorId,
        bool $requiresHandoff = false,
        ?int $existingLearnedResponseId = null
    ): bool {
        $validator = User::find($validatorId);
        if (!$validator) {
            return false;
        }

        return $this->validateAndLearn($message, $validator, $correctedContent, null, $requiresHandoff, $existingLearnedResponseId);
    }

    /**
     * Apprend d'un message avec question et réponse personnalisées.
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
     * Récupère les statistiques d'apprentissage.
     */
    public function getStats(): array
    {
        $total = LearnedResponse::count();
        $byAgent = LearnedResponse::selectRaw('agent_id, COUNT(*) as count')
            ->groupBy('agent_id')
            ->with('agent:id,name,slug')
            ->get()
            ->mapWithKeys(fn ($item) => [
                $item->agent?->slug ?? 'unknown' => $item->count
            ])
            ->toArray();

        $problematic = LearnedResponse::problematic()->count();

        return [
            'total_learned' => $total,
            'by_agent' => $byAgent,
            'problematic_count' => $problematic,
        ];
    }

    /**
     * Trouve une LearnedResponse par son ID Qdrant point.
     */
    public function findByQdrantPointId(string $pointId): ?LearnedResponse
    {
        return LearnedResponse::where('qdrant_point_id', $pointId)->first();
    }

    /**
     * Indexe une réponse validée (méthode de compatibilité).
     * Délègue à createOrUpdateLearnedResponse.
     *
     * @deprecated Utiliser createOrUpdateLearnedResponse directement
     */
    public function indexLearnedResponse(
        string $question,
        string $answer,
        int $agentId,
        string $agentSlug,
        int $messageId,
        int $validatorId,
        bool $requiresHandoff = false,
        ?int $existingLearnedResponseId = null
    ): bool {
        // Si on a un ID de LearnedResponse existant (direct_qr_match), on incrémente
        if ($existingLearnedResponseId) {
            return $this->incrementValidation($existingLearnedResponseId, $validatorId, $answer, $requiresHandoff);
        }

        return $this->createOrUpdateLearnedResponse(
            agentId: $agentId,
            question: $question,
            answer: $answer,
            userId: $validatorId,
            sourceMessageId: $messageId,
            requiresHandoff: $requiresHandoff,
            source: LearnedResponse::SOURCE_AI_VALIDATION
        );
    }

    /**
     * Supprime une LearnedResponse.
     * L'Observer gère automatiquement la suppression dans Qdrant.
     */
    public function delete(int $learnedResponseId): bool
    {
        try {
            $lr = LearnedResponse::find($learnedResponseId);
            if (!$lr) {
                return false;
            }

            $lr->delete();

            Log::info('LearnedResponse deleted', ['learned_response_id' => $learnedResponseId]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete LearnedResponse', [
                'error' => $e->getMessage(),
                'learned_response_id' => $learnedResponseId,
            ]);
            return false;
        }
    }
}
