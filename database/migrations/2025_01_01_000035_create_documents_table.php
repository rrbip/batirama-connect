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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();

            // Fichier original
            $table->string('original_name');
            $table->string('storage_path', 500);
            $table->string('mime_type', 100);
            $table->bigInteger('file_size'); // En bytes
            $table->string('file_hash', 64)->nullable(); // SHA-256

            // Classification
            $table->string('document_type', 50);
            $table->string('category', 100)->nullable();

            // Extraction
            $table->string('extraction_status', 20)->default('pending');
            $table->text('extracted_text')->nullable();
            $table->jsonb('extraction_metadata')->nullable();
            $table->text('extraction_error')->nullable();
            $table->timestamp('extracted_at')->nullable();

            // Chunking
            $table->integer('chunk_count')->default(0);
            $table->string('chunk_strategy', 50)->default('paragraph');

            // Indexation Qdrant
            $table->boolean('is_indexed')->default(false);
            $table->timestamp('indexed_at')->nullable();

            // Métadonnées utilisateur
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('source_url', 2048)->nullable();

            // Upload
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('agent_id');
            $table->index('document_type');
            $table->index('extraction_status');
            $table->index('is_indexed');
            $table->index('file_hash');
        });

        // Full-text search index
        DB::statement("CREATE INDEX idx_documents_content_search ON documents USING GIN(to_tsvector('french', COALESCE(title, '') || ' ' || COALESCE(extracted_text, '')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
