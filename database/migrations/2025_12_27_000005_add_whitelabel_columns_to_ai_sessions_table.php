<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            // Lien artisan ↔ éditeur utilisé (si session via éditeur)
            $table->foreignId('editor_link_id')
                ->nullable()
                ->after('partner_id')
                ->constrained('user_editor_links')
                ->nullOnDelete();

            // Déploiement utilisé
            $table->foreignId('deployment_id')
                ->nullable()
                ->after('editor_link_id')
                ->constrained('agent_deployments')
                ->nullOnDelete();

            // Client final (particulier)
            $table->foreignId('particulier_id')
                ->nullable()
                ->after('deployment_id')
                ->constrained('users')
                ->nullOnDelete();

            // Token pour les sessions standalone whitelabel
            $table->string('whitelabel_token', 128)
                ->nullable()
                ->after('particulier_id')
                ->unique();

            // Index
            $table->index('editor_link_id');
            $table->index('deployment_id');
            $table->index('particulier_id');
            $table->index('whitelabel_token');
        });
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropForeign(['editor_link_id']);
            $table->dropForeign(['deployment_id']);
            $table->dropForeign(['particulier_id']);
            $table->dropColumn(['editor_link_id', 'deployment_id', 'particulier_id', 'whitelabel_token']);
        });
    }
};
