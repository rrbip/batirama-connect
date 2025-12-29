<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Editor relation
            $table->foreignId('editor_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Webhook config
            $table->string('name');
            $table->string('url');
            $table->string('secret', 64);
            $table->jsonb('events')->default('[]'); // ['session.started', 'session.completed', 'message.received']
            $table->boolean('is_active')->default(true);

            // Retry config
            $table->unsignedTinyInteger('max_retries')->default(3);
            $table->unsignedInteger('timeout_ms')->default(5000);

            // Stats
            $table->timestamp('last_triggered_at')->nullable();
            $table->string('last_status')->nullable(); // success, failed
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);

            $table->timestamps();

            // Indexes
            $table->index('editor_id');
            $table->index('is_active');
        });

        // Webhook logs table
        Schema::create('editor_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('webhook_id')
                ->constrained('editor_webhooks')
                ->cascadeOnDelete();

            $table->string('event');
            $table->jsonb('payload');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('status'); // pending, success, failed, retrying
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->text('error_message')->nullable();

            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();

            // Indexes
            $table->index('webhook_id');
            $table->index('event');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_webhook_logs');
        Schema::dropIfExists('editor_webhooks');
    }
};
