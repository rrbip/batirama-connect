<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Refactors documents and document_chunks tables for the new pipeline architecture:
     * - Document: adds pipeline_steps (JSON), source_type; removes extraction_method, category
     * - DocumentChunk: adds useful, knowledge_units, parent_context, qdrant_points_count;
     *                  changes qdrant_point_id to qdrant_point_ids (JSON)
     */
    public function up(): void
    {
        // === DOCUMENTS TABLE ===
        Schema::table('documents', function (Blueprint $table) {
            // Add new columns
            $table->json('pipeline_steps')->nullable()->after('extraction_metadata');
            $table->string('source_type')->default('file')->after('uuid'); // 'file' or 'url'

            // Remove old columns (if they exist)
            if (Schema::hasColumn('documents', 'extraction_method')) {
                $table->dropColumn('extraction_method');
            }
            if (Schema::hasColumn('documents', 'category')) {
                $table->dropColumn('category');
            }
        });

        // === DOCUMENT_CHUNKS TABLE ===
        Schema::table('document_chunks', function (Blueprint $table) {
            // Add new columns for Q/R Atomique
            $table->boolean('useful')->default(true)->after('category_id');
            $table->json('knowledge_units')->nullable()->after('useful');
            $table->string('parent_context')->nullable()->after('knowledge_units');
            $table->integer('qdrant_points_count')->default(0)->after('qdrant_point_id');

            // Rename qdrant_point_id to qdrant_point_ids and change to JSON
            // We'll do this in a separate step to preserve data
        });

        // Change qdrant_point_id to qdrant_point_ids (JSON)
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->json('qdrant_point_ids')->nullable()->after('qdrant_points_count');
        });

        // Migrate existing qdrant_point_id values to qdrant_point_ids array
        DB::statement("
            UPDATE document_chunks
            SET qdrant_point_ids = JSON_ARRAY(qdrant_point_id)
            WHERE qdrant_point_id IS NOT NULL
        ");

        // Drop old column
        Schema::table('document_chunks', function (Blueprint $table) {
            if (Schema::hasColumn('document_chunks', 'qdrant_point_id')) {
                $table->dropColumn('qdrant_point_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // === DOCUMENT_CHUNKS TABLE ===
        Schema::table('document_chunks', function (Blueprint $table) {
            // Re-add qdrant_point_id
            $table->string('qdrant_point_id')->nullable()->after('category_id');
        });

        // Migrate first value from qdrant_point_ids back to qdrant_point_id
        DB::statement("
            UPDATE document_chunks
            SET qdrant_point_id = JSON_UNQUOTE(JSON_EXTRACT(qdrant_point_ids, '$[0]'))
            WHERE qdrant_point_ids IS NOT NULL
        ");

        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropColumn([
                'qdrant_point_ids',
                'qdrant_points_count',
                'parent_context',
                'knowledge_units',
                'useful',
            ]);
        });

        // === DOCUMENTS TABLE ===
        Schema::table('documents', function (Blueprint $table) {
            // Re-add old columns
            $table->string('extraction_method')->default('auto')->after('category');
            $table->string('category')->nullable()->after('document_type');

            // Remove new columns
            $table->dropColumn(['pipeline_steps', 'source_type']);
        });
    }
};
