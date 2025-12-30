<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FabricantProduct>
 */
class FabricantProductFactory extends Factory
{
    protected $model = FabricantProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_id' => FabricantCatalog::factory(),
            'name' => fake()->words(3, true),
            'sku' => fake()->unique()->bothify('SKU-####-??'),
            'description' => fake()->paragraph(),
            'price_ht' => fake()->randomFloat(2, 10, 1000),
            'currency' => 'EUR',
            'status' => 'active',
            'source_url' => fake()->url(),
        ];
    }

    /**
     * Set the catalog.
     */
    public function forCatalog(FabricantCatalog $catalog): static
    {
        return $this->state(fn (array $attributes) => [
            'catalog_id' => $catalog->id,
        ]);
    }
}
