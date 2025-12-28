<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add locale field to fabricant_products for multi-language support.
 *
 * Products from international manufacturers often have language variants
 * with the same name but different SKUs and descriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabricant_products', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('duplicate_of_id');
            // ISO 639-1 codes: fr, en, de, es, it, nl, etc.

            $table->index('locale');
            $table->index(['catalog_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::table('fabricant_products', function (Blueprint $table) {
            $table->dropIndex(['catalog_id', 'locale']);
            $table->dropIndex(['locale']);
            $table->dropColumn('locale');
        });
    }
};
