<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les champs pour les fonctionnalités:
 * - Détection et parsing multi-questions
 * - Mode Strict Assisté (suggestions sans documentation)
 * - Mode Apprentissage Accéléré
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Multi-questions detection
            $table->boolean('multi_question_detection_enabled')->default(false)
                ->after('human_support_enabled')
                ->comment('Active la détection et le parsing des messages multi-questions');

            $table->unsignedTinyInteger('max_questions_per_message')->default(5)
                ->after('multi_question_detection_enabled')
                ->comment('Nombre maximum de questions par message (1-10)');

            // Mode Strict Assisté
            $table->boolean('allow_suggestions_without_context')->default(true)
                ->after('max_questions_per_message')
                ->comment('Permet les suggestions IA même sans documentation en mode strict+handoff');

            // Mode Apprentissage Accéléré
            $table->boolean('accelerated_learning_mode')->default(false)
                ->after('allow_suggestions_without_context')
                ->comment('Force les agents à valider/corriger les réponses IA avant de répondre');

            $table->json('accelerated_learning_config')->nullable()
                ->after('accelerated_learning_mode')
                ->comment('Configuration du mode apprentissage accéléré (skip_reasons, require_skip_reason, etc.)');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'multi_question_detection_enabled',
                'max_questions_per_message',
                'allow_suggestions_without_context',
                'accelerated_learning_mode',
                'accelerated_learning_config',
            ]);
        });
    }
};
