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
        Schema::table('support_messages', function (Blueprint $table) {
            $table->timestamp('learned_at')->nullable()->after('read_at');
            $table->foreignId('learned_by')->nullable()->after('learned_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropForeign(['learned_by']);
            $table->dropColumn(['learned_at', 'learned_by']);
        });
    }
};
