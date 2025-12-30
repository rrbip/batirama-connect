<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_support_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Permissions spécifiques par agent
            $table->boolean('can_close_conversations')->default(true);
            $table->boolean('can_train_ai')->default(true);
            $table->boolean('can_view_analytics')->default(false);

            // Notifications
            $table->boolean('notify_on_escalation')->default(true);

            $table->timestamps();

            // Un utilisateur ne peut être assigné qu'une fois par agent
            $table->unique(['agent_id', 'user_id']);

            // Index pour les requêtes fréquentes
            $table->index('agent_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_support_users');
    }
};
