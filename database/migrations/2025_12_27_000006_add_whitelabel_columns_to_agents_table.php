<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Mode de déploiement
            $table->string('deployment_mode', 20)->default('internal')->after('is_active');
            // Valeurs : 'internal' (usage interne), 'shared' (marque blanche générique),
            //           'dedicated' (marque blanche spécialisable)

            // Activation whitelabel
            $table->boolean('is_whitelabel_enabled')->default(false)->after('deployment_mode');

            // Configuration whitelabel
            $table->jsonb('whitelabel_config')->nullable()->after('is_whitelabel_enabled');
            // Structure : {
            //   "allow_prompt_override": false,
            //   "allow_rag_override": false,
            //   "allow_model_override": false,
            //   "required_branding": true,  // Forcer "Powered by"
            //   "min_rate_limit": 30,
            //   "default_branding": {
            //     "primary_color": "#3B82F6",
            //     "chat_title": "Assistant IA",
            //     "welcome_message": "Bonjour, comment puis-je vous aider ?"
            //   }
            // }
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'deployment_mode',
                'is_whitelabel_enabled',
                'whitelabel_config',
            ]);
        });
    }
};
