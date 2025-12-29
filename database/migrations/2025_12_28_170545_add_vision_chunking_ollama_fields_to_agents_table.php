<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Vision Ollama configuration
            $table->string('vision_ollama_host')->nullable()->after('ollama_port');
            $table->integer('vision_ollama_port')->nullable()->after('vision_ollama_host');
            $table->string('vision_model')->nullable()->after('vision_ollama_port');

            // Chunking Ollama configuration
            $table->string('chunking_ollama_host')->nullable()->after('vision_model');
            $table->integer('chunking_ollama_port')->nullable()->after('chunking_ollama_host');
            $table->string('chunking_model')->nullable()->after('chunking_ollama_port');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'vision_ollama_host',
                'vision_ollama_port',
                'vision_model',
                'chunking_ollama_host',
                'chunking_ollama_port',
                'chunking_model',
            ]);
        });
    }
};
