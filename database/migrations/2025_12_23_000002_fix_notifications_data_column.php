<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convertir la colonne data de text à jsonb si la table existe
        if (Schema::hasTable('notifications')) {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
        }
    }

    public function down(): void
    {
        // Revenir à text si nécessaire
        if (Schema::hasTable('notifications')) {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text');
        }
    }
};
