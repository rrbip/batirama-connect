<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            // Identification
            $table->string('name', 100);
            $table->string('table_name', 100)->unique();
            $table->text('description')->nullable();

            // Schéma
            $table->jsonb('schema_definition');

            // Configuration Qdrant
            $table->string('qdrant_collection', 100)->nullable();
            $table->text('embedding_template')->nullable();

            // Statistiques
            $table->integer('row_count')->default(0);
            $table->integer('indexed_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('table_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_tables');
    }
};
