<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            // Contexte externe
            $table->string('external_session_id')->nullable();
            $table->jsonb('external_context')->nullable();

            // Métadonnées
            $table->string('title')->nullable();

            // Statistiques
            $table->integer('message_count')->default(0);

            // Statut
            $table->string('status', 20)->default('active');
            $table->timestamp('closed_at')->nullable();

            // Conversion (rempli par callback du partenaire)
            $table->string('conversion_status', 20)->nullable();
            $table->decimal('conversion_amount', 12, 2)->nullable();
            $table->timestamp('conversion_at')->nullable();

            // Commission (pour leads marketplace)
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('commission_amount', 12, 2)->nullable();
            $table->string('commission_status', 20)->nullable();

            $table->timestamps();

            $table->index('uuid');
            $table->index('agent_id');
            $table->index('user_id');
            $table->index('external_session_id');
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sessions');
    }
};
