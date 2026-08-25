<?php

namespace Database\Factories;

use App\Domain\Ordering\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'price_minor' => fake()->numberBetween(50_000, 5_000_000),
            'currency' => 'NGN',
        ];
    }
}
