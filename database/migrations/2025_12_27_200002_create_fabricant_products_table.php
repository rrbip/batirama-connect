<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fabricant Products - Product metadata extracted from crawled pages.
 *
 * These products form the fabricant's catalog and can be matched
 * against pre-quotes from artisans via the SKU matching service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabricant_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Parent catalog
            $table->foreignId('catalog_id')
                ->constrained('fabricant_catalogs')
                ->onDelete('cascade');

            // Source URL from crawl
            $table->foreignId('crawl_url_id')
                ->nullable()
                ->constrained('web_crawl_urls')
                ->onDelete('set null');

            // Product identification
            $table->string('sku', 100)->nullable();
            $table->string('ean', 20)->nullable();
            $table->string('manufacturer_ref', 100)->nullable();

            // Product details
            $table->string('name', 500);
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('category', 255)->nullable();

            // Pricing
            $table->decimal('price_ht', 10, 2)->nullable();
            $table->decimal('price_ttc', 10, 2)->nullable();
            $table->decimal('tva_rate', 5, 2)->default(20.00);
            $table->string('currency', 3)->default('EUR');
            $table->string('price_unit', 50)->nullable();
            // e.g., "m²", "kg", "unité", "pot de 15L"

            // Stock/availability
            $table->string('availability', 50)->nullable();
            // in_stock, out_of_stock, on_order, discontinued
            $table->integer('stock_quantity')->nullable();
            $table->integer('min_order_quantity')->nullable();
            $table->string('lead_time', 100)->nullable();

            // Media
            $table->jsonb('images')->nullable();
            // ["https://...", ...]
            $table->string('main_image_url', 2048)->nullable();
            $table->jsonb('documents')->nullable();
            // [{"type": "fiche_technique", "url": "..."}, ...]

            // Technical specifications
            $table->jsonb('specifications')->nullable();
            // {
            //   "Rendement": "10-12 m²/L",
            //   "Temps de séchage": "4h entre couches",
            //   "Conditionnement": ["5L", "10L", "15L"],
            //   ...
            // }

            // Dimensions and weight (for shipping)
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('depth_cm', 8, 2)->nullable();

            // Source tracking
            $table->string('source_url', 2048)->nullable();
            $table->string('source_hash', 64)->nullable();
            // Hash of source content to detect changes

            // Extraction metadata
            $table->string('extraction_method', 50)->nullable();
            // selector, llm, manual
            $table->float('extraction_confidence')->nullable();
            // 0.0 - 1.0 confidence score

            // Status
            $table->string('status', 20)->default('active');
            // active, inactive, pending_review, archived
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();

            // Marketplace integration
            $table->boolean('marketplace_visible')->default(true);
            $table->jsonb('marketplace_metadata')->nullable();
            // { "featured": true, "badges": ["eco"], "tags": [...] }

            $table->timestamps();
            $table->softDeletes();

            // Indexes for search and matching
            $table->index(['catalog_id', 'status']);
            $table->index('sku');
            $table->index('ean');
            $table->index('name');
            $table->unique(['catalog_id', 'sku'], 'unique_catalog_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabricant_products');
    }
};
