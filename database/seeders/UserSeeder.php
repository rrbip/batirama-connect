<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();
        $superAdminRole = Role::where('slug', 'super-admin')->first();
        $validatorRole = Role::where('slug', 'validator')->first();

        // Utilisateur Super Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@ai-manager.local'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'Administrateur',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant?->id,
                'email_verified_at' => now(),
            ]
        );
        $admin->roles()->syncWithoutDetaching([$superAdminRole->id]);

        // Utilisateur Validateur
        $validator = User::firstOrCreate(
            ['email' => 'validateur@ai-manager.local'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'Validateur IA',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant?->id,
                'email_verified_at' => now(),
            ]
        );
        $validator->roles()->syncWithoutDetaching([$validatorRole->id]);

        $this->command->info('👤 Utilisateurs créés:');
        $this->command->info('   - admin@ai-manager.local / password (Super Admin)');
        $this->command->info('   - validateur@ai-manager.local / password (Validateur)');
    }
}
