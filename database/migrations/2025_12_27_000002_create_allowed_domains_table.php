<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowed_domains', function (Blueprint $table) {
            $table->id();

            $table->foreignId('deployment_id')
                ->constrained('agent_deployments')
                ->onDelete('cascade');

            // Domaine
            $table->string('domain', 255); // "app.logicielx.fr"
            $table->boolean('is_wildcard')->default(false); // true = *.logicielx.fr

            // Environnement
            $table->string('environment', 20)->default('production');
            // Valeurs : 'production', 'staging', 'development', 'localhost'

            // Statut
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Contrainte unicité
            $table->unique(['deployment_id', 'domain']);

            // Index
            $table->index('deployment_id');
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_domains');
    }
};
