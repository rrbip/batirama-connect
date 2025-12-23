<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Acteur
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_email')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            // Action
            $table->string('action', 50); // create, update, delete, login, logout, export

            // Cible (nullable pour les événements sans modèle spécifique)
            $table->string('auditable_type', 100)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // Données
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('action');
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
