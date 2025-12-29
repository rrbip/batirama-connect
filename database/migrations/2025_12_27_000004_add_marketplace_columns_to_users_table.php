<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Informations entreprise
            $table->string('company_name', 255)->nullable()->after('email');

            $table->jsonb('company_info')->nullable()->after('company_name');
            // {
            //   "siret": "12345678901234",
            //   "address": "12 rue des Artisans, 75011 Paris",
            //   "phone": "01 23 45 67 89",
            //   "website": "https://durant-peinture.fr"
            // }

            // Branding par défaut (pour artisans principalement)
            $table->jsonb('branding')->nullable()->after('company_info');
            // {
            //   "welcome_message": "Bonjour, je suis l'assistant de {user.company_name}",
            //   "primary_color": "#E53935",
            //   "logo_url": "https://...",
            //   "signature": "L'équipe Durant Peinture"
            // }

            // Marketplace
            $table->boolean('marketplace_enabled')->default(false)->after('branding');

            // API (pour éditeurs et fabricants)
            $table->string('api_key', 100)->nullable()->unique()->after('marketplace_enabled');
            $table->string('api_key_prefix', 10)->nullable()->after('api_key');

            // Quotas et limites (pour éditeurs)
            $table->integer('max_deployments')->nullable()->after('api_key_prefix');
            $table->integer('max_sessions_month')->nullable()->after('max_deployments');
            $table->integer('max_messages_month')->nullable()->after('max_sessions_month');
            $table->integer('current_month_sessions')->default(0)->after('max_messages_month');
            $table->integer('current_month_messages')->default(0)->after('current_month_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'company_name',
                'company_info',
                'branding',
                'marketplace_enabled',
                'api_key',
                'api_key_prefix',
                'max_deployments',
                'max_sessions_month',
                'max_messages_month',
                'current_month_sessions',
                'current_month_messages',
            ]);
        });
    }
};
