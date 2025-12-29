<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add duplicate tracking to fabricant_products.
 * Allows marking products as duplicates of another product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabricant_products', function (Blueprint $table) {
            $table->foreignId('duplicate_of_id')
                ->nullable()
                ->after('crawl_url_id')
                ->constrained('fabricant_products')
                ->onDelete('set null');

            $table->index('duplicate_of_id');
            $table->index('source_hash');
        });
    }

    public function down(): void
    {
        Schema::table('fabricant_products', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropIndex(['duplicate_of_id']);
            $table->dropIndex(['source_hash']);
            $table->dropColumn('duplicate_of_id');
        });
    }
};
