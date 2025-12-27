<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Models\AiSession;
use App\Models\SessionValidationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de gestion du workflow de validation des sessions.
 *
 * Le workflow de validation suit ces étapes:
 * 1. pending → pending_client_review : quand un pré-devis est généré
 * 2. pending_client_review → client_validated : validation par le client
 * 3. client_validated → pending_master_review : envoi au master
 * 4. pending_master_review → validated : validation finale par le master
 *
 * Modes de validation (configurables par éditeur):
 * - client_first: validation client obligatoire avant master
 * - direct_master: envoi direct au master (bypass client)
 * - auto: validation automatique après X jours
 */
class ValidationWorkflow
{
    // Statuts de validation
    public const STATUS_PENDING = 'pending';
    public const STATUS_PENDING_CLIENT_REVIEW = 'pending_client_review';
    public const STATUS_CLIENT_VALIDATED = 'client_validated';
    public const STATUS_PENDING_MASTER_REVIEW = 'pending_master_review';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_REJECTED = 'rejected';

    // Modes de workflow
    public const MODE_CLIENT_FIRST = 'client_first';
    public const MODE_DIRECT_MASTER = 'direct_master';
    public const MODE_AUTO = 'auto';

    /**
     * Transitions autorisées: [from_status => [allowed_to_statuses]]
     */
    private const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_PENDING_CLIENT_REVIEW,
            self::STATUS_PENDING_MASTER_REVIEW, // mode direct_master
        ],
        self::STATUS_PENDING_CLIENT_REVIEW => [
            self::STATUS_CLIENT_VALIDATED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_CLIENT_VALIDATED => [
            self::STATUS_PENDING_MASTER_REVIEW,
        ],
        self::STATUS_PENDING_MASTER_REVIEW => [
            self::STATUS_VALIDATED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_VALIDATED => [
            // Final state, pas de transition
        ],
        self::STATUS_REJECTED => [
            self::STATUS_PENDING_CLIENT_REVIEW, // Réouverture possible
        ],
    ];

    /**
     * Soumet une session pour validation (après génération d'un pré-devis).
     */
    public function submitForValidation(AiSession $session, array $preQuoteData): AiSession
    {
        $workflowMode = $this->getWorkflowMode($session);

        // Déterminer le prochain statut selon le mode
        $nextStatus = match ($workflowMode) {
            self::MODE_DIRECT_MASTER => self::STATUS_PENDING_MASTER_REVIEW,
            default => self::STATUS_PENDING_CLIENT_REVIEW,
        };

        return $this->transition(
            $session,
            $nextStatus,
            null,
            SessionValidationLog::ACTION_SUBMITTED,
            null,
            ['pre_quote' => $preQuoteData]
        );
    }

    /**
     * Validation par le client (artisan).
     */
    public function clientValidate(AiSession $session, User $validator, ?string $comment = null): AiSession
    {
        if (!$this->canTransition($session, self::STATUS_CLIENT_VALIDATED)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$session->validation_status} to " . self::STATUS_CLIENT_VALIDATED
            );
        }

        return $this->transition(
            $session,
            self::STATUS_CLIENT_VALIDATED,
            $validator,
            SessionValidationLog::ACTION_CLIENT_VALIDATED,
            $comment
        );
    }

    /**
     * Rejet par le client.
     */
    public function clientReject(AiSession $session, User $validator, string $comment): AiSession
    {
        if (!$this->canTransition($session, self::STATUS_REJECTED)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$session->validation_status} to " . self::STATUS_REJECTED
            );
        }

        return $this->transition(
            $session,
            self::STATUS_REJECTED,
            $validator,
            SessionValidationLog::ACTION_CLIENT_REJECTED,
            $comment
        );
    }

    /**
     * Envoi au master pour validation.
     */
    public function sendToMaster(AiSession $session, ?User $sender = null): AiSession
    {
        if (!$this->canTransition($session, self::STATUS_PENDING_MASTER_REVIEW)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$session->validation_status} to " . self::STATUS_PENDING_MASTER_REVIEW
            );
        }

        // Anonymiser le projet avant envoi au master
        $anonymizer = new ProjectAnonymizer();
        $anonymizedData = $anonymizer->anonymize($session);

        return $this->transition(
            $session,
            self::STATUS_PENDING_MASTER_REVIEW,
            $sender,
            SessionValidationLog::ACTION_SUBMITTED,
            null,
            ['anonymized' => true]
        );
    }

    /**
     * Validation par le master.
     */
    public function masterValidate(AiSession $session, User $validator, ?string $comment = null): AiSession
    {
        if (!$this->canTransition($session, self::STATUS_VALIDATED)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$session->validation_status} to " . self::STATUS_VALIDATED
            );
        }

        return $this->transition(
            $session,
            self::STATUS_VALIDATED,
            $validator,
            SessionValidationLog::ACTION_MASTER_VALIDATED,
            $comment
        );
    }

    /**
     * Rejet par le master.
     */
    public function masterReject(AiSession $session, User $validator, string $comment): AiSession
    {
        if (!$this->canTransition($session, self::STATUS_REJECTED)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$session->validation_status} to " . self::STATUS_REJECTED
            );
        }

        return $this->transition(
            $session,
            self::STATUS_REJECTED,
            $validator,
            SessionValidationLog::ACTION_MASTER_REJECTED,
            $comment
        );
    }

    /**
     * Demande de modification.
     */
    public function requestModification(AiSession $session, User $requester, string $comment): AiSession
    {
        // Retour à pending_client_review pour modification
        return $this->transition(
            $session,
            self::STATUS_PENDING_CLIENT_REVIEW,
            $requester,
            SessionValidationLog::ACTION_MODIFICATION_REQUESTED,
            $comment
        );
    }

    /**
     * Promotion en learned response.
     */
    public function promoteToLearnedResponse(AiSession $session, User $promoter, ?string $comment = null): AiSession
    {
        if ($session->validation_status !== self::STATUS_VALIDATED) {
            throw new \InvalidArgumentException(
                'Only validated sessions can be promoted to learned responses'
            );
        }

        return DB::transaction(function () use ($session, $promoter, $comment) {
            // Log l'action de promotion
            SessionValidationLog::create([
                'session_id' => $session->id,
                'user_id' => $promoter->id,
                'action' => SessionValidationLog::ACTION_PROMOTED,
                'from_status' => $session->validation_status,
                'to_status' => $session->validation_status, // Pas de changement de statut
                'comment' => $comment,
            ]);

            // TODO: Créer la learned response à partir de la session
            // LearnedResponse::createFromSession($session);

            Log::info('Session promoted to learned response', [
                'session_id' => $session->uuid,
                'promoter_id' => $promoter->id,
            ]);

            return $session->fresh();
        });
    }

    /**
     * Vérifie si une transition est possible.
     */
    public function canTransition(AiSession $session, string $toStatus): bool
    {
        $currentStatus = $session->validation_status ?? self::STATUS_PENDING;
        $allowedTransitions = self::TRANSITIONS[$currentStatus] ?? [];

        return in_array($toStatus, $allowedTransitions, true);
    }

    /**
     * Retourne le prochain statut attendu.
     */
    public function getNextStatus(AiSession $session): ?string
    {
        $currentStatus = $session->validation_status ?? self::STATUS_PENDING;
        $workflowMode = $this->getWorkflowMode($session);

        return match ($currentStatus) {
            self::STATUS_PENDING => match ($workflowMode) {
                self::MODE_DIRECT_MASTER => self::STATUS_PENDING_MASTER_REVIEW,
                default => self::STATUS_PENDING_CLIENT_REVIEW,
            },
            self::STATUS_PENDING_CLIENT_REVIEW => self::STATUS_CLIENT_VALIDATED,
            self::STATUS_CLIENT_VALIDATED => self::STATUS_PENDING_MASTER_REVIEW,
            self::STATUS_PENDING_MASTER_REVIEW => self::STATUS_VALIDATED,
            default => null,
        };
    }

    /**
     * Retourne les statuts possibles depuis le statut actuel.
     */
    public function getAvailableTransitions(AiSession $session): array
    {
        $currentStatus = $session->validation_status ?? self::STATUS_PENDING;

        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * Effectue une transition de statut.
     */
    private function transition(
        AiSession $session,
        string $toStatus,
        ?User $actor,
        string $action,
        ?string $comment = null,
        array $metadata = []
    ): AiSession {
        $fromStatus = $session->validation_status ?? self::STATUS_PENDING;

        return DB::transaction(function () use ($session, $toStatus, $actor, $action, $comment, $fromStatus, $metadata) {
            // Mettre à jour le statut
            $updateData = [
                'validation_status' => $toStatus,
            ];

            // Si c'est une validation finale, enregistrer le validateur
            if (in_array($toStatus, [self::STATUS_VALIDATED, self::STATUS_CLIENT_VALIDATED], true)) {
                $updateData['validated_by'] = $actor?->id;
                $updateData['validated_at'] = now();
                $updateData['validation_comment'] = $comment;
            }

            $session->update($updateData);

            // Créer le log
            SessionValidationLog::create([
                'session_id' => $session->id,
                'user_id' => $actor?->id,
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'comment' => $comment,
                'metadata' => $metadata,
            ]);

            Log::info('Session validation status changed', [
                'session_id' => $session->uuid,
                'from' => $fromStatus,
                'to' => $toStatus,
                'action' => $action,
                'actor_id' => $actor?->id,
            ]);

            return $session->fresh();
        });
    }

    /**
     * Récupère le mode de workflow configuré pour la session.
     */
    private function getWorkflowMode(AiSession $session): string
    {
        // Chercher dans la config de l'éditeur
        $editor = $session->deployment?->editor;
        if ($editor) {
            $settings = $editor->settings ?? [];
            $mode = $settings['validation_workflow']['mode'] ?? null;
            if ($mode) {
                return $mode;
            }
        }

        // Mode par défaut: validation client d'abord
        return self::MODE_CLIENT_FIRST;
    }

    /**
     * Libellé du statut.
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'En attente',
            self::STATUS_PENDING_CLIENT_REVIEW => 'En attente validation client',
            self::STATUS_CLIENT_VALIDATED => 'Validé par client',
            self::STATUS_PENDING_MASTER_REVIEW => 'En attente validation master',
            self::STATUS_VALIDATED => 'Validé',
            self::STATUS_REJECTED => 'Rejeté',
            default => $status,
        };
    }

    /**
     * Couleur du statut.
     */
    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'gray',
            self::STATUS_PENDING_CLIENT_REVIEW, self::STATUS_PENDING_MASTER_REVIEW => 'warning',
            self::STATUS_CLIENT_VALIDATED => 'info',
            self::STATUS_VALIDATED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }
}
