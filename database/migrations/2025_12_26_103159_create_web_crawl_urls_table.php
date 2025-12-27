<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_crawl_urls', function (Blueprint $table) {
            $table->id();

            // URL (unique globalement via url_hash)
            $table->string('url', 2048);
            $table->string('url_hash', 64)->unique(); // SHA256 pour déduplication

            // Contenu partagé entre crawls
            $table->string('storage_path', 500)->nullable();
            $table->string('content_hash', 64)->nullable(); // Pour détecter changements

            // Métadonnées HTTP
            $table->integer('http_status')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->bigInteger('content_length')->nullable();
            $table->string('last_modified', 100)->nullable(); // Header Last-Modified
            $table->string('etag', 255)->nullable(); // Header ETag

            $table->timestamps();

            // Index
            $table->index('content_type');
            $table->index('http_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_crawl_urls');
    }
};
