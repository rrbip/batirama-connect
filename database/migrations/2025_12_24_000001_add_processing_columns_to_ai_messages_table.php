<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            // Statut de traitement asynchrone
            $table->string('processing_status', 20)->default('completed')->after('content');
            // Valeurs : 'pending', 'queued', 'processing', 'completed', 'failed'

            // Timestamps de traitement
            $table->timestamp('queued_at')->nullable()->after('processing_status');
            $table->timestamp('processing_started_at')->nullable()->after('queued_at');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');

            // Erreur en cas d'échec
            $table->text('processing_error')->nullable()->after('processing_completed_at');

            // ID du job Laravel pour tracking
            $table->string('job_id', 36)->nullable()->after('processing_error');

            // Compteur de tentatives
            $table->integer('retry_count')->default(0)->after('job_id');

            // Index pour les requêtes de monitoring
            $table->index(['processing_status', 'role'], 'idx_ai_messages_processing');
            $table->index('queued_at', 'idx_ai_messages_queued');
        });

        // Mettre les messages assistant existants à "completed"
        DB::table('ai_messages')
            ->where('role', 'assistant')
            ->whereNull('processing_status')
            ->update(['processing_status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropIndex('idx_ai_messages_processing');
            $table->dropIndex('idx_ai_messages_queued');

            $table->dropColumn([
                'processing_status',
                'queued_at',
                'processing_started_at',
                'processing_completed_at',
                'processing_error',
                'job_id',
                'retry_count',
            ]);
        });
    }
};
