<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test qu'un utilisateur peut être créé avec Factory.
     */
    public function test_user_can_be_created(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $this->assertNotNull($user->uuid);
    }

    /**
     * Test que le mot de passe est correctement hashé.
     */
    public function test_password_is_hashed(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertFalse(Hash::check('wrongpassword', $user->password));
    }

    /**
     * Test qu'un utilisateur peut se connecter via Auth::attempt.
     */
    public function test_registered_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $result = Auth::attempt([
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertTrue($result);
        $this->assertAuthenticated();
    }

    /**
     * Test qu'un utilisateur ne peut pas se connecter avec un mauvais mot de passe.
     */
    public function test_user_cannot_login_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('correctpassword'),
        ]);

        $result = Auth::attempt([
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertFalse($result);
        $this->assertGuest();
    }

    /**
     * Test qu'un utilisateur peut mettre à jour son profil.
     */
    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $user->update([
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);
    }

    /**
     * Test qu'un utilisateur peut se connecter après modification du mot de passe.
     */
    public function test_updated_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
        ]);

        // Modifier le mot de passe
        $user->update([
            'password' => Hash::make('newpassword123'),
        ]);

        // Test login avec nouveau mot de passe
        $result = Auth::attempt([
            'email' => 'test@example.com',
            'password' => 'newpassword123',
        ]);

        $this->assertTrue($result);
        $this->assertAuthenticated();
    }

    /**
     * Test que l'ancien mot de passe ne fonctionne plus après modification.
     */
    public function test_user_cannot_login_with_old_password(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
        ]);

        // Modifier le mot de passe
        $user->update([
            'password' => Hash::make('newpassword123'),
        ]);

        // Test login avec ancien mot de passe
        $result = Auth::attempt([
            'email' => 'test@example.com',
            'password' => 'oldpassword',
        ]);

        $this->assertFalse($result);
        $this->assertGuest();
    }

    /**
     * Test qu'un utilisateur authentifié peut se déconnecter.
     */
    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        Auth::login($user);
        $this->assertAuthenticated();

        Auth::logout();

        $this->assertGuest();
    }

    /**
     * Test qu'un utilisateur non authentifié ne peut pas accéder au panel admin.
     */
    public function test_guest_cannot_access_admin_panel(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect();
    }

    /**
     * Test qu'un utilisateur authentifié peut accéder au panel admin.
     */
    public function test_authenticated_user_can_access_admin_panel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertSuccessful();
    }
}
