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
            if (Schema::hasColumn('web_crawls', 'use_browser')) {
                $table->dropColumn('use_browser');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_crawls', function (Blueprint $table) {
            if (!Schema::hasColumn('web_crawls', 'use_browser')) {
                $table->boolean('use_browser')->default(false)->after('user_agent');
            }
        });
    }
};
