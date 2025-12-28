<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update existing records from 'local' to 'public'
        DB::table('vision_settings')
            ->where('storage_disk', 'local')
            ->update(['storage_disk' => 'public']);
    }

    public function down(): void
    {
        DB::table('vision_settings')
            ->where('storage_disk', 'public')
            ->update(['storage_disk' => 'local']);
    }
};
