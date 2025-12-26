<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            // Texte original avant réécriture par l'IA
            $table->text('original_content')->nullable()->after('content');

            // Résumé généré par l'IA
            $table->string('summary', 500)->nullable()->after('metadata');

            // Mots-clés extraits par l'IA
            $table->jsonb('keywords')->default('[]')->after('summary');

            // Catégorie du chunk
            $table->foreignId('category_id')
                ->nullable()
                ->after('keywords')
                ->constrained('document_categories')
                ->nullOnDelete();
        });

        // Index pour recherche par mots-clés
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->index('category_id');
        });

        // Index GIN pour recherche dans les keywords JSON
        DB::statement('CREATE INDEX idx_document_chunks_keywords ON document_chunks USING GIN(keywords)');
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropIndex('idx_document_chunks_keywords');
            $table->dropIndex(['category_id']);
            $table->dropColumn(['original_content', 'summary', 'keywords', 'category_id']);
        });
    }
};
