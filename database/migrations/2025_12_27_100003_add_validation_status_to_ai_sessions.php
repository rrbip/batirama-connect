<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les colonnes de validation à la table ai_sessions.
 *
 * Le workflow de validation permet:
 * - Validation par le client (artisan) avant envoi au master
 * - Validation par le master avant promotion en learned response
 * - Historique des validations avec commentaires
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            // Statut de validation
            // pending: nouveau, pas encore de pré-devis
            // pending_client_review: pré-devis généré, en attente validation client
            // client_validated: validé par client, en attente master
            // pending_master_review: en attente validation master
            // validated: validé par master
            // rejected: rejeté (client ou master)
            $table->string('validation_status', 30)
                ->default('pending')
                ->after('status');

            // Qui a validé
            $table->foreignId('validated_by')
                ->nullable()
                ->after('validation_status')
                ->constrained('users')
                ->nullOnDelete();

            // Quand la validation a eu lieu
            $table->timestamp('validated_at')
                ->nullable()
                ->after('validated_by');

            // Commentaire de validation/rejet
            $table->text('validation_comment')
                ->nullable()
                ->after('validated_at');

            // Données du pré-devis extrait (copie pour historique)
            $table->jsonb('pre_quote_data')
                ->nullable()
                ->after('validation_comment');

            // Projet anonymisé pour le master
            $table->jsonb('anonymized_project')
                ->nullable()
                ->after('pre_quote_data');

            // Index pour les requêtes de validation
            $table->index('validation_status');
            $table->index(['validation_status', 'deployment_id']);
        });

        // Table d'historique des actions de validation
        Schema::create('session_validation_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('session_id')
                ->constrained('ai_sessions')
                ->onDelete('cascade');

            // L'utilisateur qui a effectué l'action
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // L'action effectuée
            // submitted: session soumise pour validation
            // client_validated: validé par client
            // client_rejected: rejeté par client
            // master_validated: validé par master
            // master_rejected: rejeté par master
            // promoted: promu en learned response
            // modification_requested: modification demandée
            $table->string('action', 50);

            // Ancien statut -> nouveau statut
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);

            // Commentaire associé à l'action
            $table->text('comment')->nullable();

            // Métadonnées supplémentaires
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index(['session_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_validation_logs');

        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropIndex(['validation_status', 'deployment_id']);
            $table->dropIndex(['validation_status']);

            $table->dropColumn([
                'validation_status',
                'validated_by',
                'validated_at',
                'validation_comment',
                'pre_quote_data',
                'anonymized_project',
            ]);
        });
    }
};
