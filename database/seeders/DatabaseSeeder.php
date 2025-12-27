<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            RolePermissionSeeder::class,
            MarketplaceRolesSeeder::class,
            UserSeeder::class,
            AgentSeeder::class,
            OuvrageSeeder::class,
            SupportDocSeeder::class,
            PartnerSeeder::class,
        ]);
    }
}
