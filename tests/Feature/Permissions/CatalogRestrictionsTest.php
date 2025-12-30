<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Filament\Resources\FabricantCatalogResource;
use App\Models\FabricantCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $fabricant;

    protected Role $fabricantRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $adminRole = Role::factory()->admin()->create();
        $this->fabricantRole = Role::factory()->fabricant()->create();

        // Create admin user
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        // Create fabricant user with website URL
        $this->fabricant = User::factory()->create([
            'company_info' => [
                'website' => 'https://www.mon-fabricant.fr',
                'siret' => '12345678901234',
            ],
        ]);
        $this->fabricant->roles()->attach($this->fabricantRole);
    }

    // ==========================================
    // Catalog Creation Restrictions
    // ==========================================

    public function test_fabricant_has_catalog_returns_false_when_no_catalog(): void
    {
        $this->actingAs($this->fabricant);

        $result = FabricantCatalogResource::fabricantHasCatalog();

        $this->assertFalse($result);
    }

    public function test_fabricant_has_catalog_returns_true_when_catalog_exists(): void
    {
        $this->actingAs($this->fabricant);

        // Create a catalog for the fabricant
        FabricantCatalog::factory()->create([
            'fabricant_id' => $this->fabricant->id,
            'website_url' => 'https://www.mon-fabricant.fr',
        ]);

        $result = FabricantCatalogResource::fabricantHasCatalog();

        $this->assertTrue($result);
    }

    public function test_admin_fabricant_has_catalog_returns_false(): void
    {
        // Give admin the fabricant role too
        $this->admin->roles()->attach($this->fabricantRole);

        $this->actingAs($this->admin);

        // Create a catalog for the admin
        FabricantCatalog::factory()->create([
            'fabricant_id' => $this->admin->id,
        ]);

        // Admin should not be restricted
        $result = FabricantCatalogResource::fabricantHasCatalog();

        $this->assertFalse($result);
    }

    // ==========================================
    // Website URL from Profile
    // ==========================================

    public function test_get_fabricant_website_url_returns_url_from_profile(): void
    {
        $this->actingAs($this->fabricant);

        $url = FabricantCatalogResource::getFabricantWebsiteUrl();

        $this->assertEquals('https://www.mon-fabricant.fr', $url);
    }

    public function test_get_fabricant_website_url_returns_null_when_not_set(): void
    {
        $fabricantWithoutUrl = User::factory()->create([
            'company_info' => null,
        ]);
        $fabricantWithoutUrl->roles()->attach($this->fabricantRole);

        $this->actingAs($fabricantWithoutUrl);

        $url = FabricantCatalogResource::getFabricantWebsiteUrl();

        $this->assertNull($url);
    }

    public function test_get_fabricant_website_url_returns_null_when_empty_array(): void
    {
        $fabricantWithoutUrl = User::factory()->create([
            'company_info' => [],
        ]);
        $fabricantWithoutUrl->roles()->attach($this->fabricantRole);

        $this->actingAs($fabricantWithoutUrl);

        $url = FabricantCatalogResource::getFabricantWebsiteUrl();

        $this->assertNull($url);
    }

    // ==========================================
    // isFabricantOnly Check
    // ==========================================

    public function test_is_fabricant_only_returns_true_for_fabricant(): void
    {
        $this->actingAs($this->fabricant);

        $result = FabricantCatalogResource::isFabricantOnly();

        $this->assertTrue($result);
    }

    public function test_is_fabricant_only_returns_false_for_admin(): void
    {
        $this->actingAs($this->admin);

        $result = FabricantCatalogResource::isFabricantOnly();

        $this->assertFalse($result);
    }

    public function test_is_fabricant_only_returns_false_for_fabricant_with_admin_role(): void
    {
        // Give fabricant admin role
        $adminRole = Role::where('slug', 'admin')->first();
        $this->fabricant->roles()->attach($adminRole);

        $this->actingAs($this->fabricant);

        $result = FabricantCatalogResource::isFabricantOnly();

        $this->assertFalse($result);
    }

    // ==========================================
    // Navigation Badge
    // ==========================================

    public function test_navigation_badge_shows_fabricant_catalog_count(): void
    {
        $this->actingAs($this->fabricant);

        // Create catalogs - one for this fabricant, one for another
        FabricantCatalog::factory()->create([
            'fabricant_id' => $this->fabricant->id,
        ]);

        $otherFabricant = User::factory()->create();
        $otherFabricant->roles()->attach($this->fabricantRole);
        FabricantCatalog::factory()->create([
            'fabricant_id' => $otherFabricant->id,
        ]);

        $badge = FabricantCatalogResource::getNavigationBadge();

        $this->assertEquals('1', $badge);
    }

    public function test_navigation_badge_shows_all_catalogs_for_admin(): void
    {
        $this->actingAs($this->admin);

        // Create multiple catalogs
        FabricantCatalog::factory()->count(3)->create();

        $badge = FabricantCatalogResource::getNavigationBadge();

        $this->assertEquals('3', $badge);
    }
}
