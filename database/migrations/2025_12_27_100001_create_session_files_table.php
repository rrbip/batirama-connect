<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relations
            $table->foreignId('session_id')
                ->constrained('ai_sessions')
                ->cascadeOnDelete();

            // File info
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('storage_disk')->default('public');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');

            // Thumbnail (for images)
            $table->string('thumbnail_path')->nullable();

            // Type classification
            $table->string('file_type')->default('document'); // image, video, audio, pdf, document

            // Metadata
            $table->jsonb('metadata')->nullable();

            // Processing status
            $table->string('status')->default('uploaded'); // uploaded, processing, ready, failed

            $table->timestamps();

            // Indexes
            $table->index('session_id');
            $table->index('file_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_files');
    }
};
