<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Position dans le document
            $table->integer('chunk_index');
            $table->integer('start_offset')->nullable();
            $table->integer('end_offset')->nullable();
            $table->integer('page_number')->nullable();

            // Contenu
            $table->text('content');
            $table->string('content_hash', 64);
            $table->integer('token_count')->nullable();

            // Métadonnées contextuelles
            $table->text('context_before')->nullable();
            $table->text('context_after')->nullable();
            $table->jsonb('metadata')->nullable();

            // Indexation Qdrant
            $table->string('qdrant_point_id', 100)->nullable();
            $table->boolean('is_indexed')->default(false);
            $table->timestamp('indexed_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('document_id');
            $table->index('is_indexed');
            $table->index('content_hash');
            $table->unique(['document_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
