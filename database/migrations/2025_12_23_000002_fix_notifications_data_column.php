<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convertir la colonne data de text à jsonb si nécessaire
        if (!Schema::hasTable('notifications')) {
            return;
        }

        // Vérifier si la colonne est déjà jsonb
        $columnType = DB::selectOne("
            SELECT data_type FROM information_schema.columns
            WHERE table_name = 'notifications' AND column_name = 'data'
        ");

        if ($columnType && $columnType->data_type !== 'jsonb') {
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
