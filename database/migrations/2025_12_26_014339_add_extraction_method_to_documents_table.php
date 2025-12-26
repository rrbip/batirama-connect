<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Méthode d'extraction pour les PDFs :
            // - auto : Essaie toutes les méthodes et choisit la meilleure (défaut)
            // - text : Force l'extraction texte uniquement (pdftotext + pdfparser)
            // - ocr : Force l'OCR (conversion en images + Tesseract)
            $table->string('extraction_method', 20)->default('auto')->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('extraction_method');
        });
    }
};
