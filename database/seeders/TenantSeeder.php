<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::firstOrCreate(
            ['slug' => 'default'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'AI-Manager CMS',
                'domain' => 'localhost',
                'settings' => [
                    'theme' => 'light',
                    'locale' => 'fr',
                ],
                'is_active' => true,
            ]
        );

        $this->command->info('🏢 Tenant par défaut créé');
    }
}
