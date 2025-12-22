<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();

            // Liaison agent
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            // Liaison application tierce
            $table->string('external_app', 100)->nullable();
            $table->string('external_ref')->nullable();
            $table->jsonb('external_meta')->nullable();

            // Session créée
            $table->foreignId('session_id')->nullable()->constrained('ai_sessions')->nullOnDelete();

            // Infos client
            $table->jsonb('client_info')->nullable();

            // Validité et sécurité
            $table->timestamp('expires_at');
            $table->integer('max_uses')->default(1);
            $table->integer('use_count')->default(0);

            // Statut
            $table->string('status', 20)->default('active');

            // Tracking
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->ipAddress('last_ip')->nullable();
            $table->text('last_user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('token');
            $table->index('agent_id');
            $table->index(['external_app', 'external_ref']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_access_tokens');
    }
};
