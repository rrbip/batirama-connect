<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ouvrage_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('ouvrages')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('ouvrages')->cascadeOnDelete();

            $table->decimal('quantity', 10, 4)->default(1);
            $table->string('unit', 20)->nullable();

            $table->integer('sort_order')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['parent_id', 'component_id']);
            $table->index('parent_id');
            $table->index('component_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ouvrage_components');
    }
};
