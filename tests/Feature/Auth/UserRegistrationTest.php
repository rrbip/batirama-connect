<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test que l'email doit être unique.
     */
    public function test_duplicate_email_rejected(): void
    {
        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);
    }

    /**
     * Test que le UUID est auto-généré si non fourni.
     */
    public function test_uuid_is_auto_generated(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->assertNotNull($user->uuid);
        $this->assertTrue(Str::isUuid($user->uuid));
    }

    /**
     * Test que le password est hashé automatiquement via le cast.
     */
    public function test_password_is_auto_hashed(): void
    {
        $user = User::factory()->create([
            'password' => 'plaintext123',
        ]);

        // Le password ne doit pas être en clair
        $this->assertNotEquals('plaintext123', $user->password);
        // Le password doit être vérifié via Hash::check
        $this->assertTrue(Hash::check('plaintext123', $user->password));
    }

    /**
     * Test que les champs sensibles sont cachés lors de la sérialisation.
     */
    public function test_sensitive_fields_are_hidden(): void
    {
        $user = User::factory()->create();

        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
        $this->assertArrayNotHasKey('api_key', $array);
    }

    /**
     * Test que l'email peut être vérifié.
     */
    public function test_email_verification(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertNull($user->email_verified_at);

        $user->update(['email_verified_at' => now()]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /**
     * Test que plusieurs utilisateurs peuvent être créés avec des emails différents.
     */
    public function test_multiple_users_with_different_emails(): void
    {
        $user1 = User::factory()->create(['email' => 'user1@example.com']);
        $user2 = User::factory()->create(['email' => 'user2@example.com']);
        $user3 = User::factory()->create(['email' => 'user3@example.com']);

        $this->assertCount(3, User::all());
        $this->assertNotEquals($user1->id, $user2->id);
        $this->assertNotEquals($user2->id, $user3->id);
    }

    /**
     * Test que le soft delete fonctionne.
     */
    public function test_user_can_be_soft_deleted(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->deleted_at);

        $user->delete();

        $this->assertSoftDeleted($user);
        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    /**
     * Test que le soft delete peut être restauré.
     */
    public function test_soft_deleted_user_can_be_restored(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertSoftDeleted($user);

        $user->restore();

        $this->assertNull($user->fresh()->deleted_at);
        $this->assertNotNull(User::find($user->id));
    }

    /**
     * Test que l'utilisateur a un nom.
     */
    public function test_user_has_name(): void
    {
        $user = User::factory()->create(['name' => 'John Doe']);

        $this->assertEquals('John Doe', $user->name);
    }

    /**
     * Test que l'utilisateur a un email valide.
     */
    public function test_user_has_valid_email(): void
    {
        $user = User::factory()->create(['email' => 'valid@email.com']);

        $this->assertEquals('valid@email.com', $user->email);
        $this->assertMatchesRegularExpression('/^[^@]+@[^@]+\.[^@]+$/', $user->email);
    }
}
