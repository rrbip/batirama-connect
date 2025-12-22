<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enable ltree extension for hierarchical paths
        DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');

        Schema::create('ouvrages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            // Hiérarchie
            $table->foreignId('parent_id')->nullable()->constrained('ouvrages')->nullOnDelete();
            $table->integer('depth')->default(0);

            // Identification
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();

            // Classification
            $table->string('type', 50); // compose, simple, fourniture, main_oeuvre

            $table->string('category', 100)->nullable();
            $table->string('subcategory', 100)->nullable();

            // Prix
            $table->string('unit', 20); // m², ml, U, h, kg, etc.
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->string('currency', 3)->default('EUR');

            // Quantités
            $table->decimal('default_quantity', 10, 4)->default(1);

            // Métadonnées techniques
            $table->jsonb('technical_specs')->default('{}');

            // Indexation Qdrant
            $table->boolean('is_indexed')->default(false);
            $table->timestamp('indexed_at')->nullable();
            $table->string('qdrant_point_id', 100)->nullable();

            // Source import
            $table->string('import_source', 50)->nullable();
            $table->string('import_id', 100)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('code');
            $table->index('type');
            $table->index('is_indexed');
            $table->index('tenant_id');
        });

        // Add ltree column for hierarchical paths
        DB::statement('ALTER TABLE ouvrages ADD COLUMN path ltree');
        DB::statement('CREATE INDEX idx_ouvrages_path ON ouvrages USING GIST(path)');

        // Full-text search index
        DB::statement("CREATE INDEX idx_ouvrages_search ON ouvrages USING GIN(to_tsvector('french', name || ' ' || COALESCE(description, '')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ouvrages');
    }
};
