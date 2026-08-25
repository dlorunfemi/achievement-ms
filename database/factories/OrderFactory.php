<?php

namespace Database\Factories;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 3);

        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'amount_minor' => $quantity * fake()->numberBetween(50_000, 500_000),
            'currency' => 'NGN',
            'status' => OrderStatus::Pending,
            'placed_at' => now(),
        ];
    }

    /**
     * An order of a fixed size and value.
     *
     * The spend and loyalty groups are scored on money and dates rather than order
     * counts, so a test about purchase COUNT pins the amount and keeps those other
     * progressions deterministically out of the picture.
     */
    public function worth(int $amountMinor, int $quantity = 1): static
    {
        return $this->state(fn (): array => [
            'amount_minor' => $amountMinor,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Only completed orders count toward purchase achievements.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Completed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Cancelled]);
    }
}
