<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->string('logo_url', 500)->nullable();

            // Authentification API
            $table->string('api_key', 64)->unique();
            $table->string('api_key_prefix', 10);

            // Configuration
            $table->string('webhook_url')->nullable();
            $table->string('default_agent', 50)->default('expert-btp');

            // Niveau d'accès aux données
            $table->string('data_access', 20)->default('summary'); // summary, full, custom
            $table->jsonb('data_fields')->nullable();

            // Commission
            $table->decimal('commission_rate', 5, 2)->default(5.00);

            // Notifications
            $table->boolean('notify_on_session_complete')->default(true);
            $table->boolean('notify_on_conversion')->default(true);

            // Statistiques
            $table->integer('sessions_count')->default(0);
            $table->integer('conversions_count')->default(0);
            $table->decimal('total_commission', 12, 2)->default(0);

            // Statut
            $table->string('status', 20)->default('active'); // active, inactive, suspended

            // Contact
            $table->string('contact_email')->nullable();
            $table->string('contact_name', 100)->nullable();

            $table->timestamps();

            $table->index('slug');
            $table->index('api_key');
        });

        // Add foreign key on ai_sessions for partner
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('tenant_id')->constrained('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });

        Schema::dropIfExists('partners');
    }
};
