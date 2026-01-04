<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learned_responses', function (Blueprint $table) {
            $table->id();
            $table->uuid('qdrant_point_id')->nullable()->unique();

            // Relation avec l'agent
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();

            // Question / Réponse
            $table->text('question');
            $table->text('answer');

            // Compteurs de validation
            $table->unsignedInteger('validation_count')->default(1);
            $table->unsignedInteger('rejection_count')->default(0);

            // Métadonnées
            $table->boolean('requires_handoff')->default(false);
            $table->string('source')->default('ai_validation'); // ai_validation, manual, import

            // Message source (si créé via validation d'un message IA)
            $table->foreignId('source_message_id')
                ->nullable()
                ->constrained('ai_messages')
                ->nullOnDelete();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_validated_at')->nullable();

            $table->timestamps();

            // Index pour recherche rapide
            $table->index('agent_id');
            $table->index('source_message_id');
            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learned_responses');
    }
};
