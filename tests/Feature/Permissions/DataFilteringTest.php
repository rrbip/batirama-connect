<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $fabricant1;

    protected User $fabricant2;

    protected FabricantCatalog $catalog1;

    protected FabricantCatalog $catalog2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $superAdminRole = Role::factory()->superAdmin()->create();
        $adminRole = Role::factory()->admin()->create();
        $fabricantRole = Role::factory()->fabricant()->create();

        // Create users with roles
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->roles()->attach($superAdminRole);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->fabricant1 = User::factory()->create();
        $this->fabricant1->roles()->attach($fabricantRole);

        $this->fabricant2 = User::factory()->create();
        $this->fabricant2->roles()->attach($fabricantRole);

        // Create catalogs for each fabricant
        $this->catalog1 = FabricantCatalog::factory()->create([
            'fabricant_id' => $this->fabricant1->id,
            'name' => 'Catalog Fabricant 1',
        ]);

        $this->catalog2 = FabricantCatalog::factory()->create([
            'fabricant_id' => $this->fabricant2->id,
            'name' => 'Catalog Fabricant 2',
        ]);

        // Create products for each catalog
        FabricantProduct::factory()->count(3)->create([
            'catalog_id' => $this->catalog1->id,
        ]);

        FabricantProduct::factory()->count(2)->create([
            'catalog_id' => $this->catalog2->id,
        ]);
    }

    // ==========================================
    // Catalog Filtering Tests
    // ==========================================

    public function test_admin_sees_all_catalogs(): void
    {
        $this->actingAs($this->admin);

        $query = FabricantCatalog::query();

        // Apply the same filtering logic as in the resource
        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $query->where('fabricant_id', $user->id);
        }

        $catalogs = $query->get();

        $this->assertCount(2, $catalogs);
        $this->assertTrue($catalogs->contains('id', $this->catalog1->id));
        $this->assertTrue($catalogs->contains('id', $this->catalog2->id));
    }

    public function test_super_admin_sees_all_catalogs(): void
    {
        $this->actingAs($this->superAdmin);

        $query = FabricantCatalog::query();

        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $query->where('fabricant_id', $user->id);
        }

        $catalogs = $query->get();

        $this->assertCount(2, $catalogs);
    }

    public function test_fabricant_sees_only_own_catalogs(): void
    {
        $this->actingAs($this->fabricant1);

        $query = FabricantCatalog::query();

        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $query->where('fabricant_id', $user->id);
        }

        $catalogs = $query->get();

        $this->assertCount(1, $catalogs);
        $this->assertEquals($this->catalog1->id, $catalogs->first()->id);
    }

    public function test_fabricant_cannot_see_other_fabricant_catalogs(): void
    {
        $this->actingAs($this->fabricant1);

        $query = FabricantCatalog::query();

        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $query->where('fabricant_id', $user->id);
        }

        $catalogs = $query->get();

        $this->assertFalse($catalogs->contains('id', $this->catalog2->id));
    }

    // ==========================================
    // Product Filtering Tests
    // ==========================================

    public function test_admin_sees_all_products(): void
    {
        $this->actingAs($this->admin);

        $query = FabricantProduct::query();

        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $catalogIds = FabricantCatalog::where('fabricant_id', $user->id)->pluck('id');
            $query->whereIn('catalog_id', $catalogIds);
        }

        $products = $query->get();

        $this->assertCount(5, $products); // 3 + 2
    }

    public function test_fabricant_sees_only_own_products(): void
    {
        $this->actingAs($this->fabricant1);

        $query = FabricantProduct::query();

        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $catalogIds = FabricantCatalog::where('fabricant_id', $user->id)->pluck('id');
            $query->whereIn('catalog_id', $catalogIds);
        }

        $products = $query->get();

        $this->assertCount(3, $products);
        $this->assertTrue($products->every(fn ($p) => $p->catalog_id === $this->catalog1->id));
    }

    public function test_fabricant_cannot_see_other_fabricant_products(): void
    {
        $this->actingAs($this->fabricant1);

        $query = FabricantProduct::query();

        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $catalogIds = FabricantCatalog::where('fabricant_id', $user->id)->pluck('id');
            $query->whereIn('catalog_id', $catalogIds);
        }

        $products = $query->get();

        $this->assertFalse($products->contains(fn ($p) => $p->catalog_id === $this->catalog2->id));
    }

    // ==========================================
    // Edge Cases
    // ==========================================

    public function test_fabricant_with_no_catalogs_sees_empty_list(): void
    {
        $fabricantRole = Role::where('slug', 'fabricant')->first();
        $newFabricant = User::factory()->create();
        $newFabricant->roles()->attach($fabricantRole);

        $this->actingAs($newFabricant);

        $query = FabricantCatalog::query();

        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $query->where('fabricant_id', $user->id);
        }

        $catalogs = $query->get();

        $this->assertCount(0, $catalogs);
    }

    public function test_fabricant_with_admin_role_sees_all(): void
    {
        // Give fabricant1 admin role as well
        $adminRole = Role::where('slug', 'admin')->first();
        $this->fabricant1->roles()->attach($adminRole);

        $this->actingAs($this->fabricant1);

        $query = FabricantCatalog::query();

        $user = auth()->user();
        if ($user && $user->hasRole('fabricant') && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')) {
            $query->where('fabricant_id', $user->id);
        }

        $catalogs = $query->get();

        // Should see all because has admin role
        $this->assertCount(2, $catalogs);
    }
}
