<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_editor_links', function (Blueprint $table) {
            $table->id();

            // L'artisan (user avec role artisan)
            $table->foreignId('artisan_id')
                ->constrained('users')
                ->onDelete('cascade');

            // L'éditeur (user avec role editeur)
            $table->foreignId('editor_id')
                ->constrained('users')
                ->onDelete('cascade');

            // ID de l'artisan dans le système de l'éditeur
            $table->string('external_id', 100); // "DUR-001" chez EBP

            // Branding spécifique pour cet éditeur (override user.branding)
            $table->jsonb('branding')->nullable();
            // {
            //   "welcome_message": "Assistant EBP - Durant Peinture",
            //   "primary_color": "#1E88E5",
            //   "logo_url": "https://...",
            //   "signature": "Durant Peinture via EBP"
            // }

            // Permissions spécifiques chez cet éditeur
            $table->jsonb('permissions')->nullable();
            // {
            //   "can_create_sessions": true,
            //   "can_view_analytics": false,
            //   "max_sessions_month": 100
            // }

            // Statut
            $table->boolean('is_active')->default(true);
            $table->timestamp('linked_at')->useCurrent();

            // Contraintes unicité
            $table->unique(['artisan_id', 'editor_id']);
            $table->unique(['editor_id', 'external_id']);

            // Index
            $table->index('artisan_id');
            $table->index('editor_id');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_editor_links');
    }
};
