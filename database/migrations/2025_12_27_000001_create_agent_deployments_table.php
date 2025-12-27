<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_deployments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relations
            $table->foreignId('agent_id')->constrained()->onDelete('cascade');
            $table->foreignId('editor_id')->constrained('users')->onDelete('cascade');

            // Identification
            $table->string('name'); // "Expert BTP - EBP"
            $table->string('deployment_key', 64)->unique(); // Clé publique pour le widget

            // Mode de déploiement
            $table->string('deployment_mode', 20)->default('shared');
            // Valeurs : 'shared' (générique), 'dedicated' (spécialisé)

            // Overlay de configuration (surcharge l'agent de base)
            $table->jsonb('config_overlay')->nullable();
            // Structure : {
            //   "system_prompt_append": "Instructions spécifiques...",
            //   "system_prompt_replace": null,
            //   "welcome_message": "Bienvenue !",
            //   "placeholder": "Posez votre question...",
            //   "max_tokens": 1500,
            //   "temperature": 0.6
            // }

            // Personnalisation visuelle
            $table->jsonb('branding')->nullable();
            // Structure : {
            //   "primary_color": "#3B82F6",
            //   "logo_url": "https://...",
            //   "chat_title": "Assistant",
            //   "powered_by": true,
            //   "custom_css": "..."
            // }

            // Collection RAG dédiée (si mode dedicated)
            $table->string('dedicated_collection', 100)->nullable();

            // Limites spécifiques
            $table->integer('max_sessions_day')->nullable();
            $table->integer('max_messages_day')->nullable();
            $table->integer('rate_limit_per_ip')->default(60);

            // Statistiques
            $table->integer('sessions_count')->default(0);
            $table->integer('messages_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();

            // Statut
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Contrainte unicité
            $table->unique(['agent_id', 'editor_id', 'name']);

            // Index
            $table->index('deployment_key');
            $table->index('agent_id');
            $table->index('editor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_deployments');
    }
};
