<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configurable_lists', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Identifiant unique de la liste');
            $table->string('name')->comment('Nom affiché');
            $table->string('description')->nullable()->comment('Description de la liste');
            $table->string('category')->default('general')->comment('Catégorie pour regroupement');
            $table->json('data')->comment('Données clé-valeur de la liste');
            $table->boolean('is_system')->default(false)->comment('Liste système non supprimable');
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configurable_lists');
    }
};
