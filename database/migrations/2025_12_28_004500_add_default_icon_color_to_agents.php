<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('icon')->nullable()->default('heroicon-o-chat-bubble-left-right')->change();
            $table->string('color')->nullable()->default('primary')->change();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('icon')->nullable(false)->default(null)->change();
            $table->string('color')->nullable(false)->default(null)->change();
        });
    }
};
