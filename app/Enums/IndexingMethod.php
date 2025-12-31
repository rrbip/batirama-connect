<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Méthodes d'indexation pour les documents RAG.
 *
 * Définit comment les documents sont transformés et indexés dans Qdrant.
 * Pour l'instant seule la méthode Q/R Atomique est implémentée,
 * mais l'architecture est prévue pour ajouter d'autres méthodes.
 */
enum IndexingMethod: string
{
    case QR_ATOMIQUE = 'qr_atomique';

    // Futures méthodes possibles :
    // case DEVIS_STRUCTURE = 'devis_structure';  // Pour Expert BTP - génération de devis
    // case HIERARCHICAL = 'hierarchical';        // Indexation hiérarchique
    // case SUMMARY_TREE = 'summary_tree';        // Arbre de résumés

    public function label(): string
    {
        return match ($this) {
            self::QR_ATOMIQUE => 'Q/R Atomique',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::QR_ATOMIQUE => 'Génère des paires Question/Réponse autonomes pour chaque chunk. Optimal pour FAQ et documentation.',
        };
    }

    /**
     * Retourne les champs du payload Qdrant pour cette méthode.
     */
    public function getPayloadFields(): array
    {
        return match ($this) {
            self::QR_ATOMIQUE => [
                'type',           // qa_pair ou source_material
                'display_text',   // Contenu à afficher
                'question',       // Question (qa_pair uniquement)
                'category',       // Catégorie du chunk
                'source_doc',     // Titre du document source
                'parent_context', // Contexte hiérarchique
                'chunk_id',       // ID du chunk en base
                'document_id',    // ID du document en base
                'agent_id',       // ID de l'agent
            ],
        };
    }
}
