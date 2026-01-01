<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les champs pour supporter plusieurs providers LLM par agent.
 *
 * Permet de configurer Ollama (self-hosted) ou APIs cloud (Gemini, OpenAI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Provider LLM (ollama par défaut pour rétrocompatibilité)
            $table->string('llm_provider', 20)->default('ollama')->after('model');

            // Clé API pour les providers cloud (chiffrée)
            $table->text('llm_api_key')->nullable()->after('llm_provider');

            // Modèle spécifique pour API (ex: gemini-2.5-flash)
            // Distinct du champ 'model' utilisé pour Ollama
            $table->string('llm_api_model', 100)->nullable()->after('llm_api_key');

            // Index pour filtrage par provider
            $table->index('llm_provider');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['llm_provider']);
            $table->dropColumn(['llm_provider', 'llm_api_key', 'llm_api_model']);
        });
    }
};
