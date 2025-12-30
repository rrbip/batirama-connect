<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Filament\Resources\AgentResource;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\DocumentCategoryResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\FabricantCatalogResource;
use App\Filament\Resources\FabricantProductResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $fabricant;

    protected User $artisan;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $superAdminRole = Role::factory()->superAdmin()->create();
        $adminRole = Role::factory()->admin()->create();
        $fabricantRole = Role::factory()->fabricant()->create();
        $artisanRole = Role::factory()->artisan()->create();

        // Create users with roles
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->roles()->attach($superAdminRole);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->fabricant = User::factory()->create();
        $this->fabricant->roles()->attach($fabricantRole);

        $this->artisan = User::factory()->create();
        $this->artisan->roles()->attach($artisanRole);
    }

    // ==========================================
    // Admin-only Resources
    // ==========================================

    public function test_user_resource_requires_admin(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(UserResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(UserResource::canAccess());

        $this->actingAs($this->fabricant);
        $this->assertFalse(UserResource::canAccess());

        $this->actingAs($this->artisan);
        $this->assertFalse(UserResource::canAccess());
    }

    public function test_role_resource_requires_admin(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(RoleResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(RoleResource::canAccess());

        $this->actingAs($this->fabricant);
        $this->assertFalse(RoleResource::canAccess());
    }

    public function test_document_resource_requires_admin(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(DocumentResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(DocumentResource::canAccess());

        $this->actingAs($this->fabricant);
        $this->assertFalse(DocumentResource::canAccess());
    }

    public function test_agent_resource_requires_admin(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(AgentResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(AgentResource::canAccess());

        $this->actingAs($this->fabricant);
        $this->assertFalse(AgentResource::canAccess());
    }

    public function test_document_category_resource_requires_admin(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(DocumentCategoryResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(DocumentCategoryResource::canAccess());

        $this->actingAs($this->fabricant);
        $this->assertFalse(DocumentCategoryResource::canAccess());
    }

    public function test_audit_log_resource_requires_admin(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(AuditLogResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(AuditLogResource::canAccess());

        $this->actingAs($this->fabricant);
        $this->assertFalse(AuditLogResource::canAccess());
    }

    // ==========================================
    // Fabricant-accessible Resources
    // ==========================================

    public function test_fabricant_catalog_allows_fabricant(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(FabricantCatalogResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(FabricantCatalogResource::canAccess());

        $this->actingAs($this->fabricant);
        $this->assertTrue(FabricantCatalogResource::canAccess());

        $this->actingAs($this->artisan);
        $this->assertFalse(FabricantCatalogResource::canAccess());
    }

    public function test_fabricant_product_allows_fabricant(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(FabricantProductResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(FabricantProductResource::canAccess());

        $this->actingAs($this->fabricant);
        $this->assertTrue(FabricantProductResource::canAccess());

        $this->actingAs($this->artisan);
        $this->assertFalse(FabricantProductResource::canAccess());
    }
}
