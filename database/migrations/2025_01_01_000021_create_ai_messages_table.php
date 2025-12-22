<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('session_id')->constrained('ai_sessions')->cascadeOnDelete();

            // Type de message
            $table->string('role', 20); // user, assistant, system

            // Contenu
            $table->text('content');

            // Pièces jointes
            $table->jsonb('attachments')->nullable();

            // Métadonnées RAG
            $table->jsonb('rag_context')->nullable();

            // Métadonnées de génération
            $table->string('model_used', 100)->nullable();
            $table->integer('tokens_prompt')->nullable();
            $table->integer('tokens_completion')->nullable();
            $table->integer('generation_time_ms')->nullable();

            // Validation humaine
            $table->string('validation_status', 20)->default('pending');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();

            // Réponse corrigée
            $table->text('corrected_content')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id');
            $table->index('role');
            $table->index('validation_status');
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
