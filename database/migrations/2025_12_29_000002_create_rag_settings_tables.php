<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vision LLM Settings
        Schema::create('vision_settings', function (Blueprint $table) {
            $table->id();
            $table->string('model')->nullable();
            $table->string('ollama_host')->default('ollama');
            $table->integer('ollama_port')->default(11434);
            $table->float('temperature')->default(0.3);
            $table->integer('timeout_seconds')->default(300);
            $table->text('system_prompt')->nullable();
            $table->timestamps();
        });

        // Q/R Atomique Settings
        Schema::create('qr_atomique_settings', function (Blueprint $table) {
            $table->id();
            $table->string('model')->nullable();
            $table->string('ollama_host')->default('ollama');
            $table->integer('ollama_port')->default(11434);
            $table->float('temperature')->default(0.3);
            $table->integer('threshold')->default(1500);
            $table->integer('timeout_seconds')->default(120);
            $table->text('system_prompt')->nullable();
            $table->timestamps();
        });

        // Pipeline Tools Settings (default tools per file type)
        Schema::create('pipeline_tools_settings', function (Blueprint $table) {
            $table->id();
            $table->json('pdf_tools')->nullable();
            $table->json('image_tools')->nullable();
            $table->json('html_tools')->nullable();
            $table->json('markdown_tools')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_tools_settings');
        Schema::dropIfExists('qr_atomique_settings');
        Schema::dropIfExists('vision_settings');
    }
};
