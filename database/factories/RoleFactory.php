<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Create a super-admin role.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'description' => 'Accès total au système',
        ]);
    }

    /**
     * Create an admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Admin',
            'slug' => 'admin',
            'description' => 'Administrateur',
        ]);
    }

    /**
     * Create a fabricant role.
     */
    public function fabricant(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Fabricant',
            'slug' => 'fabricant',
            'description' => 'Fabricant de produits',
        ]);
    }

    /**
     * Create an artisan role.
     */
    public function artisan(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Artisan',
            'slug' => 'artisan',
            'description' => 'Artisan utilisateur',
        ]);
    }
}
