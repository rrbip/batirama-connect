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
            $table->float('min_rag_score')->nullable()->after('max_rag_results');
            $table->integer('max_learned_responses')->nullable()->after('min_rag_score');
            $table->float('learned_min_score')->nullable()->after('max_learned_responses');
            $table->integer('context_token_limit')->nullable()->after('learned_min_score');
            $table->boolean('strict_mode')->default(false)->after('context_token_limit');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'min_rag_score',
                'max_learned_responses',
                'learned_min_score',
                'context_token_limit',
                'strict_mode',
            ]);
        });
    }
};
