<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les champs de configuration RAG par agent.
     *
     * Ces champs permettent de personnaliser le comportement du RAG
     * pour chaque agent depuis le backoffice, au lieu d'utiliser
     * les valeurs globales de config/ai.php.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Score minimum pour les résultats RAG (0.0 - 1.0)
            // Permet d'ajuster la pertinence requise par agent
            $table->float('min_rag_score')
                ->default(0.5)
                ->after('max_rag_results')
                ->comment('Score minimum de similarité pour les résultats RAG (0.0-1.0)');

            // Nombre max de réponses apprises à inclure dans le contexte
            $table->integer('max_learned_responses')
                ->default(3)
                ->after('min_rag_score')
                ->comment('Nombre maximum de réponses apprises à inclure');

            // Score minimum pour les réponses apprises
            $table->float('learned_min_score')
                ->default(0.75)
                ->after('max_learned_responses')
                ->comment('Score minimum pour les réponses apprises (0.0-1.0)');

            // Limite de tokens pour le contexte RAG
            $table->integer('context_token_limit')
                ->default(4000)
                ->after('learned_min_score')
                ->comment('Limite de tokens pour le contexte documentaire');

            // Mode strict: ajoute automatiquement des garde-fous anti-hallucination
            $table->boolean('strict_mode')
                ->default(false)
                ->after('context_token_limit')
                ->comment('Ajoute des garde-fous automatiques contre les hallucinations');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'min_rag_score',
                'max_learned_responses',
                'learned_min_score',
                'context_token_limit',
                'strict_mode',
            ]);
        });
    }
};
