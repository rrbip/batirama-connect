<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();

            // Requête
            $table->string('event', 100);
            $table->jsonb('payload');

            // Réponse
            $table->integer('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('response_time_ms')->nullable();

            // Résultat
            $table->string('status', 20); // success, failed, pending

            $table->text('error_message')->nullable();
            $table->integer('attempt_number')->default(1);

            $table->timestamp('created_at')->useCurrent();

            $table->index('webhook_id');
            $table->index('status');
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
