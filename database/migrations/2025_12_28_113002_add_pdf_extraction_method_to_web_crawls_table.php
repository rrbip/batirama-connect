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
        Schema::table('web_crawls', function (Blueprint $table) {
            // PDF extraction method for locale detection: auto, text, ocr
            $table->string('pdf_extraction_method', 10)->default('auto')->after('dedup_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_crawls', function (Blueprint $table) {
            $table->dropColumn('pdf_extraction_method');
        });
    }
};
