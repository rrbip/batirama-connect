<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#6B7280');

            $table->boolean('is_ai_generated')->default(false);
            $table->integer('usage_count')->default(0);

            $table->timestamps();

            $table->index('name');
            $table->index('is_ai_generated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_categories');
    }
};
