<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_crawls', function (Blueprint $table) {
            $table->boolean('use_browser')->default(false)->after('user_agent')
                ->comment('Use FlareSolverr/headless browser for Cloudflare bypass');
        });
    }

    public function down(): void
    {
        Schema::table('web_crawls', function (Blueprint $table) {
            $table->dropColumn('use_browser');
        });
    }
};
