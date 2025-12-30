<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        Role::factory()->superAdmin()->create();
        Role::factory()->admin()->create();
        Role::factory()->fabricant()->create();
        Role::factory()->artisan()->create();
    }

    public function test_super_admin_can_access_panel(): void
    {
        $role = Role::where('slug', 'super-admin')->first();
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $panel = Filament::getPanel('admin');
        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_admin_can_access_panel(): void
    {
        $role = Role::where('slug', 'admin')->first();
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $panel = Filament::getPanel('admin');
        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_fabricant_can_access_panel(): void
    {
        $role = Role::where('slug', 'fabricant')->first();
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $panel = Filament::getPanel('admin');
        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_artisan_cannot_access_panel(): void
    {
        $role = Role::where('slug', 'artisan')->first();
        $user = User::factory()->create();
        $user->roles()->attach($role);

        // Set environment to production to avoid dev fallback
        app()->detectEnvironment(fn () => 'production');

        $panel = Filament::getPanel('admin');
        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_user_without_role_cannot_access_panel(): void
    {
        $user = User::factory()->create();

        // Set environment to production to avoid dev fallback
        app()->detectEnvironment(fn () => 'production');

        $panel = Filament::getPanel('admin');
        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_user_has_role_method_works(): void
    {
        $fabricantRole = Role::where('slug', 'fabricant')->first();
        $user = User::factory()->create();
        $user->roles()->attach($fabricantRole);

        $this->assertTrue($user->hasRole('fabricant'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('super-admin'));
    }

    public function test_user_is_super_admin_method_works(): void
    {
        $superAdminRole = Role::where('slug', 'super-admin')->first();
        $user = User::factory()->create();
        $user->roles()->attach($superAdminRole);

        $this->assertTrue($user->isSuperAdmin());

        $fabricantRole = Role::where('slug', 'fabricant')->first();
        $fabricant = User::factory()->create();
        $fabricant->roles()->attach($fabricantRole);

        $this->assertFalse($fabricant->isSuperAdmin());
    }
}
