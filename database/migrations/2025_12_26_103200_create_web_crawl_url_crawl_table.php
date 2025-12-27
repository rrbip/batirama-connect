<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_crawl_url_crawl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_id')->constrained('web_crawls')->cascadeOnDelete();
            $table->foreignId('crawl_url_id')->constrained('web_crawl_urls')->cascadeOnDelete();

            // Hiérarchie (URL parente pour l'arborescence)
            $table->foreignId('parent_id')->nullable()->constrained('web_crawl_url_crawl')->nullOnDelete();
            $table->integer('depth')->default(0);

            // Statut pour ce crawl spécifique
            $table->string('status', 20)->default('pending');
            // pending, fetching, fetched, indexed, skipped, error

            // Filtrage
            $table->string('matched_pattern', 500)->nullable();
            $table->string('skip_reason', 100)->nullable();
            // pattern_exclude, pattern_not_include, robots_txt, unsupported_type,
            // content_too_large, auth_required, timeout, http_error, etc.

            // Résultat
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);

            // Timestamps
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            // Index
            $table->unique(['crawl_id', 'crawl_url_id']);
            $table->index(['crawl_id', 'status']);
            $table->index(['crawl_id', 'depth']);
            $table->index('skip_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_crawl_url_crawl');
    }
};
