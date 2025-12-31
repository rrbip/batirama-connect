<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\AiSession;
use App\Models\LearnedResponse;
use App\Models\SupportMessage;
use App\Services\AI\QdrantService;
use Illuminate\Support\Facades\Log;

class SupportTrainingService
{
    public function __construct(
        protected QdrantService $qdrantService
    ) {}

    /**
     * Sauvegarde une Q/R atomique depuis le support.
     */
    public function saveQrPair(
        AiSession $session,
        string $question,
        string $answer,
        int $validatorId,
        ?int $messageId = null
    ): ?LearnedResponse {
        $agent = $session->agent;

        if (!$agent) {
            Log::warning('Cannot save Q/R pair: no agent on session', [
                'session_id' => $session->id,
            ]);
            return null;
        }

        try {
            // Créer la learned_response
            $learned = LearnedResponse::create([
                'agent_id' => $agent->id,
                'session_id' => $session->id,
                'question' => trim($question),
                'answer' => trim($answer),
                'source' => 'human_support',
                'validated_by' => $validatorId,
                'validated_at' => now(),
                'is_active' => true,
                'metadata' => [
                    'support_message_id' => $messageId,
                    'created_from' => 'support_chat',
                ],
            ]);

            // Indexer immédiatement dans Qdrant
            $indexed = $this->indexLearnedResponse($learned);

            if ($indexed) {
                Log::info('Q/R pair saved and indexed from support', [
                    'learned_response_id' => $learned->id,
                    'session_id' => $session->id,
                    'agent_id' => $agent->id,
                ]);
            }

            return $learned;

        } catch (\Throwable $e) {
            Log::error('Failed to save Q/R pair from support', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Indexe une learned_response dans Qdrant.
     */
    protected function indexLearnedResponse(LearnedResponse $learned): bool
    {
        $agent = $learned->agent;

        if (!$agent) {
            return false;
        }

        try {
            // Générer l'embedding pour la question
            $embedding = $this->qdrantService->generateEmbedding($learned->question);

            if (empty($embedding)) {
                Log::warning('Failed to generate embedding for learned response', [
                    'learned_response_id' => $learned->id,
                ]);
                return false;
            }

            // Préparer le payload
            $payload = [
                'type' => 'learned_response',
                'learned_response_id' => $learned->id,
                'question' => $learned->question,
                'answer' => $learned->answer,
                'content' => $learned->question . "\n\n" . $learned->answer,
                'source' => $learned->source,
                'agent_id' => $agent->id,
                'created_at' => $learned->created_at->toIso8601String(),
            ];

            // Indexer dans Qdrant
            $pointId = 'learned_' . $learned->id;
            $collection = $agent->qdrant_collection ?? ('agent_' . $agent->slug);

            $this->qdrantService->upsertPoints($collection, [
                [
                    'id' => $pointId,
                    'vector' => $embedding,
                    'payload' => $payload,
                ],
            ]);

            // Marquer comme indexé
            $learned->update([
                'indexed_at' => now(),
                'qdrant_point_id' => $pointId,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('Failed to index learned response in Qdrant', [
                'learned_response_id' => $learned->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Sauvegarde une Q/R depuis un message de support spécifique.
     */
    public function saveFromSupportMessage(
        SupportMessage $message,
        string $question,
        int $validatorId
    ): ?LearnedResponse {
        $session = $message->session;

        if (!$session) {
            return null;
        }

        return $this->saveQrPair(
            $session,
            $question,
            $message->content,
            $validatorId,
            $message->id
        );
    }

    /**
     * Récupère les Q/R en attente de validation pour une session.
     */
    public function getPendingQrPairs(AiSession $session): array
    {
        $pairs = [];

        // Récupérer les messages utilisateur suivis de réponses admin
        $messages = $session->supportMessages()
            ->orderBy('created_at')
            ->get();

        $userQuestion = null;

        foreach ($messages as $message) {
            if ($message->sender_type === 'user') {
                $userQuestion = $message->content;
            } elseif ($message->sender_type === 'agent' && $userQuestion) {
                // Vérifier si cette paire n'est pas déjà enregistrée
                $exists = LearnedResponse::where('session_id', $session->id)
                    ->where('metadata->support_message_id', $message->id)
                    ->exists();

                if (!$exists) {
                    $pairs[] = [
                        'message_id' => $message->id,
                        'question' => $userQuestion,
                        'answer' => $message->content,
                        'created_at' => $message->created_at,
                    ];
                }

                $userQuestion = null;
            }
        }

        return $pairs;
    }
}
