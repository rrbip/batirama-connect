<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_crawls', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();

            // Configuration
            $table->string('start_url', 2048);
            $table->jsonb('allowed_domains')->default('[]');
            $table->string('url_filter_mode', 10)->default('exclude'); // 'exclude' ou 'include'
            $table->jsonb('url_patterns')->default('[]');
            $table->integer('max_depth')->default(5);
            $table->integer('max_pages')->default(500);
            $table->integer('max_disk_mb')->nullable(); // NULL = illimité
            $table->integer('delay_ms')->default(500);
            $table->boolean('respect_robots_txt')->default(true);
            $table->string('user_agent', 500)->default('IA-Manager/1.0');

            // Authentification
            $table->string('auth_type', 20)->default('none'); // none, basic, cookies
            $table->text('auth_credentials')->nullable(); // Chiffré via encrypt()
            $table->jsonb('custom_headers')->default('{}');

            // Statistiques
            $table->string('status', 20)->default('pending');
            // pending, running, paused, completed, failed, cancelled
            $table->integer('pages_discovered')->default(0);
            $table->integer('pages_crawled')->default(0);
            $table->integer('pages_indexed')->default(0);
            $table->integer('pages_skipped')->default(0);
            $table->integer('pages_error')->default(0);
            $table->integer('documents_found')->default(0);
            $table->integer('images_found')->default(0);
            $table->bigInteger('total_size_bytes')->default(0);

            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Index
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_crawls');
    }
};
