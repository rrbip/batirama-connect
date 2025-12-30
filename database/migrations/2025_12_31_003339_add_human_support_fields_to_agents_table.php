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
        Schema::table('agents', function (Blueprint $table) {
            // Human support configuration
            $table->boolean('human_support_enabled')->default(false)->after('whitelabel_config');
            $table->float('escalation_threshold')->default(0.60)->after('human_support_enabled');
            $table->text('escalation_message')->nullable()->after('escalation_threshold');
            $table->text('no_admin_message')->nullable()->after('escalation_message');
            $table->string('support_email')->nullable()->after('no_admin_message');
            $table->json('support_hours')->nullable()->after('support_email');
            $table->json('ai_assistance_config')->nullable()->after('support_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'human_support_enabled',
                'escalation_threshold',
                'escalation_message',
                'no_admin_message',
                'support_email',
                'support_hours',
                'ai_assistance_config',
            ]);
        });
    }
};
