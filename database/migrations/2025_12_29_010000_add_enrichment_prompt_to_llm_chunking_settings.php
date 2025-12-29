<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_chunking_settings', function (Blueprint $table) {
            $table->text('enrichment_prompt')->nullable()->after('system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('llm_chunking_settings', function (Blueprint $table) {
            $table->dropColumn('enrichment_prompt');
        });
    }
};
