<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            // Identification
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 50)->default('robot');
            $table->string('color', 7)->default('#3B82F6');

            // Configuration IA
            $table->text('system_prompt');

            // Configuration Qdrant
            $table->string('qdrant_collection', 100);

            // Mode de récupération
            $table->string('retrieval_mode', 20)->default('TEXT_ONLY');
            $table->jsonb('hydration_config')->nullable();

            // Configuration Ollama (override)
            $table->string('ollama_host')->nullable();
            $table->integer('ollama_port')->nullable();
            $table->string('model', 100)->nullable();
            $table->string('fallback_model', 100)->nullable();

            // Paramètres de contexte
            $table->integer('context_window_size')->default(10);
            $table->integer('max_tokens')->default(2048);
            $table->decimal('temperature', 3, 2)->default(0.7);

            // Configuration RAG avancée
            $table->integer('max_rag_results')->default(5);
            $table->boolean('allow_iterative_search')->default(false);
            $table->string('response_format', 20)->default('text');
            $table->boolean('allow_attachments')->default(true);

            // Configuration accès public
            $table->boolean('allow_public_access')->default(false);
            $table->integer('default_token_expiry_hours')->default(168);

            // Statut
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('slug');
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
