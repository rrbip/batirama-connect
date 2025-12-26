<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Méthode d'extraction par défaut pour les PDFs
            // auto : Essaie toutes les méthodes et choisit la meilleure
            // text : Force l'extraction texte (pdftotext + pdfparser)
            // ocr : Force l'OCR (Tesseract)
            $table->string('default_extraction_method', 20)->default('auto');

            // Stratégie de chunking par défaut
            // sentence : Par phrase (recommandé)
            // paragraph : Par paragraphe
            // fixed_size : Taille fixe
            // recursive : Récursif
            $table->string('default_chunk_strategy', 50)->default('sentence');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['default_extraction_method', 'default_chunk_strategy']);
        });
    }
};
