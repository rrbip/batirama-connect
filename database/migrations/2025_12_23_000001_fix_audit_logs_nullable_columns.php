<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rendre auditable_type nullable si la table existe déjà
        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->string('auditable_type', 100)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Optionnel: revenir en arrière (mais risqué si des données NULL existent)
    }
};
