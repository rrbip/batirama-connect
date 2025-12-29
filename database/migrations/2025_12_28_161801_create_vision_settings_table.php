<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_settings', function (Blueprint $table) {
            $table->id();

            // Modèle vision à utiliser
            $table->string('model')->default('moondream');

            // Configuration Ollama
            $table->string('ollama_host')->default('ollama');
            $table->integer('ollama_port')->default(11434);

            // Paramètres d'extraction
            $table->integer('image_dpi')->default(300); // Résolution des images générées
            $table->string('output_format')->default('markdown'); // markdown, text, json
            $table->integer('max_pages')->default(50); // Limite de pages par document
            $table->integer('timeout_seconds')->default(120); // Timeout par page

            // Prompt système pour l'extraction
            $table->text('system_prompt')->nullable();

            // Stockage des fichiers intermédiaires
            $table->boolean('store_images')->default(true);
            $table->boolean('store_markdown')->default(true);
            $table->string('storage_disk')->default('public'); // public, local, s3, etc.
            $table->string('storage_path')->default('vision-extraction');

            // Informations système (affichage uniquement)
            $table->json('system_requirements')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_settings');
    }
};
