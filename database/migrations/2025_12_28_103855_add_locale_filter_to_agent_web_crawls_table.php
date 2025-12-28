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
        Schema::table('agent_web_crawls', function (Blueprint $table) {
            // Locale filter: array of allowed locales (empty = all locales allowed)
            $table->json('allowed_locales')->nullable()->after('content_types');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_web_crawls', function (Blueprint $table) {
            $table->dropColumn('allowed_locales');
        });
    }
};
