<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\AiSession;
use Illuminate\Support\Str;

class ConversationToMarkdownService
{
    /**
     * Convertit une session de support en document Markdown
     * optimisé pour le prompt Q/R existant.
     */
    public function convert(AiSession $session): string
    {
        $title = $this->extractTitle($session);
        $category = $this->detectCategory($session);

        $markdown = "# Résolution Support: {$title}\n\n";
        $markdown .= "**Agent IA**: {$session->agent?->name}\n";
        $markdown .= "**Date**: {$session->created_at->format('d/m/Y')}\n";
        $markdown .= "**Catégorie**: {$category}\n\n";
        $markdown .= "---\n\n";

        // Ajouter les messages IA (contexte initial)
        $aiMessages = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get();

        if ($aiMessages->isNotEmpty()) {
            $markdown .= "## Conversation initiale avec l'IA\n\n";

            foreach ($aiMessages as $msg) {
                if ($msg->role === 'user') {
                    $markdown .= "**Utilisateur**: {$msg->content}\n\n";
                } else {
                    $markdown .= "**IA**: {$msg->content}\n\n";
                }
            }

            $markdown .= "---\n\n";
        }

        // Ajouter les messages de support
        $supportMessages = $session->supportMessages()
            ->whereIn('sender_type', ['user', 'agent'])
            ->orderBy('created_at')
            ->get();

        if ($supportMessages->isNotEmpty()) {
            $markdown .= "## Résolution par le support\n\n";

            foreach ($supportMessages as $msg) {
                if ($msg->sender_type === 'user') {
                    $markdown .= "### Question utilisateur\n\n";
                    $markdown .= $msg->content . "\n\n";
                } else {
                    $markdown .= "### Réponse support\n\n";
                    $markdown .= $msg->content . "\n\n";
                }
            }
        }

        // Ajouter les métadonnées de résolution si disponibles
        if ($session->resolution_notes) {
            $markdown .= "---\n\n";
            $markdown .= "## Notes de résolution\n\n";
            $markdown .= $session->resolution_notes . "\n";
        }

        return $markdown;
    }

    /**
     * Extrait un titre depuis la première question utilisateur.
     */
    protected function extractTitle(AiSession $session): string
    {
        // Chercher d'abord dans les messages IA
        $firstUserMessage = $session->messages()
            ->where('role', 'user')
            ->orderBy('created_at')
            ->first();

        if ($firstUserMessage) {
            return Str::limit($firstUserMessage->content, 60);
        }

        // Sinon dans les messages de support
        $firstSupportMessage = $session->supportMessages()
            ->where('sender_type', 'user')
            ->orderBy('created_at')
            ->first();

        if ($firstSupportMessage) {
            return Str::limit($firstSupportMessage->content, 60);
        }

        return "Session #{$session->id}";
    }

    /**
     * Détecte la catégorie depuis les métadonnées.
     */
    protected function detectCategory(AiSession $session): string
    {
        // Vérifier dans les métadonnées de support
        if (isset($session->support_metadata['category_detected'])) {
            return $session->support_metadata['category_detected'];
        }

        // Vérifier dans la raison d'escalade
        if ($session->escalation_reason) {
            return match ($session->escalation_reason) {
                'low_confidence' => 'Question technique',
                'user_request' => 'Demande spécifique',
                'ai_uncertainty' => 'Cas complexe',
                'negative_feedback' => 'Amélioration nécessaire',
                default => 'Support',
            };
        }

        return 'Support';
    }

    /**
     * Génère un résumé de la conversation pour le document.
     */
    public function generateSummary(AiSession $session): string
    {
        $parts = [];

        // Résumer le problème
        $firstQuestion = $session->messages()
            ->where('role', 'user')
            ->orderBy('created_at')
            ->first();

        if ($firstQuestion) {
            $parts[] = "Problème initial: " . Str::limit($firstQuestion->content, 100);
        }

        // Résumer la résolution
        $lastAnswer = $session->supportMessages()
            ->where('sender_type', 'agent')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastAnswer) {
            $parts[] = "Résolution: " . Str::limit($lastAnswer->content, 150);
        }

        // Statut
        $parts[] = "Statut: " . match ($session->support_status) {
            'resolved' => 'Résolu',
            'abandoned' => 'Abandonné',
            default => 'En cours',
        };

        return implode("\n", $parts);
    }
}
