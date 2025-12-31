<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix knowledge_units column type from varchar(500) to JSON/TEXT.
     * This fixes the error: "value too long for type character varying(500)"
     */
    public function up(): void
    {
        // Pour PostgreSQL, on change le type en JSONB
        if (DB::getDriverName() === 'pgsql') {
            // D'abord, on vide les données malformées
            DB::statement('UPDATE document_chunks SET knowledge_units = NULL WHERE knowledge_units IS NOT NULL');

            // Puis on change le type
            DB::statement('ALTER TABLE document_chunks ALTER COLUMN knowledge_units TYPE JSONB USING knowledge_units::jsonb');
        } else {
            // Pour MySQL/SQLite, on utilise Schema builder
            Schema::table('document_chunks', function (Blueprint $table) {
                $table->json('knowledge_units')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // On ne revient pas en arrière car ça perdrait des données
    }
};
