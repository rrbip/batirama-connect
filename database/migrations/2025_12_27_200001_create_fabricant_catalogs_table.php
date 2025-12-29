<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fabricant Catalogs - Links fabricants to web crawls for product extraction.
 *
 * This table tracks crawls of fabricant websites and the status of
 * product metadata extraction from those crawls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabricant_catalogs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Fabricant who owns this catalog
            $table->foreignId('fabricant_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Associated web crawl (if any)
            $table->foreignId('web_crawl_id')
                ->nullable()
                ->constrained('web_crawls')
                ->onDelete('set null');

            // Catalog metadata
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('website_url', 2048);

            // Extraction settings
            $table->jsonb('extraction_config')->nullable();
            // {
            //   "product_url_patterns": ["*/produit/*", "*/fiche-technique/*"],
            //   "price_selector": ".product-price",
            //   "sku_selector": ".product-reference",
            //   "name_selector": "h1.product-title",
            //   "description_selector": ".product-description",
            //   "image_selector": ".product-gallery img",
            //   "specs_selector": ".product-specs table",
            //   "use_llm_extraction": true
            // }

            // Status tracking
            $table->string('status', 20)->default('pending');
            // pending, crawling, extracting, completed, failed

            $table->integer('products_found')->default(0);
            $table->integer('products_updated')->default(0);
            $table->integer('products_failed')->default(0);

            $table->timestamp('last_crawl_at')->nullable();
            $table->timestamp('last_extraction_at')->nullable();
            $table->text('last_error')->nullable();

            // Schedule for automatic refresh
            $table->string('refresh_frequency', 20)->nullable();
            // daily, weekly, monthly, manual
            $table->timestamp('next_refresh_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // A fabricant can have multiple catalogs
            $table->index(['fabricant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabricant_catalogs');
    }
};
