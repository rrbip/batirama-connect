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
            $table->string('chunk_strategy')->default('simple')->after('url_patterns');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_crawls', function (Blueprint $table) {
            $table->dropColumn('chunk_strategy');
        });
    }
};
