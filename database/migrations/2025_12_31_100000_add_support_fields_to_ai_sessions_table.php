<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            // Support status
            $table->string('support_status', 50)->nullable()->after('status');
            // null          : Pas d'escalade
            // 'escalated'   : Transférée au support, en attente
            // 'assigned'    : Un agent a pris en charge
            // 'resolved'    : Résolu
            // 'abandoned'   : Utilisateur parti sans résolution

            // Raison de l'escalade
            $table->string('escalation_reason', 50)->nullable()->after('support_status');
            // 'low_confidence'    : Score RAG trop bas
            // 'user_request'      : Utilisateur a demandé un humain
            // 'ai_uncertainty'    : IA a signalé son incertitude
            // 'negative_feedback' : Feedback négatif sur réponse IA

            $table->timestamp('escalated_at')->nullable()->after('escalation_reason');

            // Agent de support assigné
            $table->foreignId('support_agent_id')->nullable()->after('escalated_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('support_agent_id');

            // Email utilisateur (pour communication async)
            $table->string('user_email')->nullable()->after('assigned_at');

            // Résolution
            $table->timestamp('resolved_at')->nullable()->after('user_email');
            $table->string('resolution_type', 50)->nullable()->after('resolved_at');
            // 'answered'    : Question répondue
            // 'redirected'  : Redirigé vers autre service
            // 'out_of_scope': Hors périmètre
            // 'duplicate'   : Question déjà traitée

            $table->text('resolution_notes')->nullable()->after('resolution_type');

            // Token d'accès pour réponse par email
            $table->string('support_access_token', 64)->nullable()->after('resolution_notes');
            $table->timestamp('support_token_expires_at')->nullable()->after('support_access_token');

            // Métadonnées support
            $table->jsonb('support_metadata')->nullable()->after('support_token_expires_at');
            // {
            //   "max_rag_score": 0.45,
            //   "user_online": true,
            //   "last_user_activity": "2024-12-31T10:00:00Z",
            //   "notification_sent_at": "2024-12-31T10:00:00Z"
            // }

            // Index pour les requêtes support
            $table->index('support_status');
            $table->index('support_agent_id');
            $table->index(['support_status', 'escalated_at']);
            $table->index('support_access_token');
        });
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropIndex(['support_status']);
            $table->dropIndex(['support_agent_id']);
            $table->dropIndex(['support_status', 'escalated_at']);
            $table->dropIndex(['support_access_token']);

            $table->dropForeign(['support_agent_id']);

            $table->dropColumn([
                'support_status',
                'escalation_reason',
                'escalated_at',
                'support_agent_id',
                'assigned_at',
                'user_email',
                'resolved_at',
                'resolution_type',
                'resolution_notes',
                'support_access_token',
                'support_token_expires_at',
                'support_metadata',
            ]);
        });
    }
};
