<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FabricantCatalog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FabricantCatalog>
 */
class FabricantCatalogFactory extends Factory
{
    protected $model = FabricantCatalog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Catalogue ' . fake()->year(),
            'fabricant_id' => User::factory(),
            'website_url' => fake()->url(),
            'description' => fake()->sentence(),
            'status' => FabricantCatalog::STATUS_PENDING,
            'refresh_frequency' => FabricantCatalog::REFRESH_WEEKLY,
            'extraction_config' => [
                'product_url_patterns' => ['*/produit/*'],
                'use_llm_extraction' => true,
            ],
        ];
    }

    /**
     * Set the fabricant.
     */
    public function forFabricant(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'fabricant_id' => $user->id,
        ]);
    }

    /**
     * Set status to completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FabricantCatalog::STATUS_COMPLETED,
        ]);
    }
}
