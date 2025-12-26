<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Lien vers le crawl source (si le document provient d'un crawl web)
            $table->foreignId('web_crawl_id')
                ->nullable()
                ->constrained('web_crawls')
                ->nullOnDelete();

            // Lien vers l'URL crawlée (pour le partage de contenu)
            $table->foreignId('crawl_url_id')
                ->nullable()
                ->constrained('web_crawl_urls')
                ->nullOnDelete();

            // Index
            $table->index('web_crawl_id');
            $table->index('crawl_url_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['web_crawl_id']);
            $table->dropForeign(['crawl_url_id']);
            $table->dropColumn(['web_crawl_id', 'crawl_url_id']);
        });
    }
};
