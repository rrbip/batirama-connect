<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add deduplication options to web_crawls
        Schema::table('web_crawls', function (Blueprint $table) {
            $table->boolean('enable_deduplication')->default(true)->after('custom_headers');
            $table->string('dedup_mode', 20)->default('content_hash')->after('enable_deduplication');
            // content_hash = exact duplicate by content
            // canonical = use canonical URL
            // both = canonical first, then content_hash
        });

        // Add canonical_url to web_crawl_urls for HTML pages
        Schema::table('web_crawl_urls', function (Blueprint $table) {
            $table->string('canonical_url', 2048)->nullable()->after('url_hash');
            $table->string('canonical_hash', 64)->nullable()->after('canonical_url');
            $table->foreignId('duplicate_of_id')->nullable()->after('canonical_hash')
                ->constrained('web_crawl_urls')->nullOnDelete();

            $table->index('canonical_hash');
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('web_crawl_urls', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropIndex(['canonical_hash']);
            $table->dropIndex(['content_hash']);
            $table->dropColumn(['canonical_url', 'canonical_hash', 'duplicate_of_id']);
        });

        Schema::table('web_crawls', function (Blueprint $table) {
            $table->dropColumn(['enable_deduplication', 'dedup_mode']);
        });
    }
};
