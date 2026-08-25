<?php

namespace Database\Seeders;

use App\Domain\Cashback\Models\PayoutAccount;
use App\Domain\Ordering\Actions\CompleteOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A demo store: products, users, and enough purchase history for every rung of the
 * catalog to be visible without anyone having to place orders by hand.
 *
 * Orders are driven through CompleteOrder, so the seeded state is produced by the
 * real OrderCompleted -> achievements -> badge -> cashback chain rather than written
 * straight into the unlock tables. That is the point of the seeder: what you see
 * after seeding is what the application actually does.
 */
class DemoSeeder extends Seeder
{
    /**
     * @var list<array{name: string, price_minor: int}>
     */
    private const PRODUCTS = [
        ['name' => 'Zobo Concentrate Pack', 'price_minor' => 280_000],
        ['name' => 'Beaded Waist Chain', 'price_minor' => 350_000],
        ['name' => 'Shea Butter Body Cream', 'price_minor' => 420_000],
        ['name' => 'Adire Throw Pillow', 'price_minor' => 680_000],
        ['name' => 'Stainless Water Bottle', 'price_minor' => 950_000],
        ['name' => 'Ankara Print Tote Bag', 'price_minor' => 1_250_000],
        ['name' => 'Recycled Glass Tumbler Set', 'price_minor' => 1_500_000],
        ['name' => 'Leather Sandals', 'price_minor' => 1_800_000],
        ['name' => 'Cotton Kaftan', 'price_minor' => 2_200_000],
        ['name' => 'Wireless Earbuds', 'price_minor' => 2_800_000],
        ['name' => 'Solar Power Bank', 'price_minor' => 3_500_000],
        ['name' => 'Bluetooth Speaker', 'price_minor' => 4_500_000],
    ];

    /**
     * Each profile is a shape of shopper, chosen so the seeded store covers every
     * progression the catalog defines and both outcomes of a payout.
     *
     * completed: orders driven through CompleteOrder.
     * products:  distinct products those orders are spread across (feeds variety).
     * days:      distinct days those orders are spread over (feeds loyalty).
     *
     * @var list<array{name: string, email: string, completed: int, products: int, days: int, pending: int, cancelled: int, bank: bool}>
     */
    private const SHOPPERS = [
        [
            // Signed up, bought nothing: no achievements, no badge, but a next step.
            'name' => 'Ada Okonkwo', 'email' => 'ada@bumpa.test',
            'completed' => 0, 'products' => 0, 'days' => 0,
            'pending' => 0, 'cancelled' => 0, 'bank' => true,
        ],
        [
            // The brief's opening case: one purchase, one achievement, first badge.
            'name' => 'Chinedu Balogun', 'email' => 'chinedu@bumpa.test',
            'completed' => 1, 'products' => 1, 'days' => 1,
            'pending' => 1, 'cancelled' => 0, 'bank' => true,
        ],
        [
            // The brief's worked example: five purchases, three short of Advanced.
            'name' => 'Amara Eze', 'email' => 'amara@bumpa.test',
            'completed' => 5, 'products' => 2, 'days' => 1,
            'pending' => 0, 'cancelled' => 2, 'bank' => true,
        ],
        [
            'name' => 'Tunde Adeyemi', 'email' => 'tunde@bumpa.test',
            'completed' => 12, 'products' => 4, 'days' => 7,
            'pending' => 1, 'cancelled' => 1, 'bank' => true,
        ],
        [
            'name' => 'Zainab Yusuf', 'email' => 'zainab@bumpa.test',
            'completed' => 27, 'products' => 10, 'days' => 12,
            'pending' => 0, 'cancelled' => 0, 'bank' => true,
        ],
        [
            // Far enough along to hold badges the purchases group alone cannot reach.
            'name' => 'Emeka Obi', 'email' => 'emeka@bumpa.test',
            'completed' => 55, 'products' => 12, 'days' => 30,
            'pending' => 2, 'cancelled' => 1, 'bank' => true,
        ],
        [
            // Nothing but abandoned and cancelled orders: unlocks nothing at all.
            'name' => 'Fatima Bello', 'email' => 'fatima@bumpa.test',
            'completed' => 0, 'products' => 0, 'days' => 0,
            'pending' => 3, 'cancelled' => 4, 'bank' => true,
        ],
        [
            // Earns a badge with no bank account on file: the failed payout path.
            'name' => 'Segun Lawal', 'email' => 'segun@bumpa.test',
            'completed' => 3, 'products' => 2, 'days' => 2,
            'pending' => 0, 'cancelled' => 0, 'bank' => false,
        ],
    ];

    public function run(): void
    {
        if (! $this->isSafeToSeed()) {
            return;
        }

        /*
         * Unlocking and paying are queued listeners. Seeding on the database queue
         * would leave a pile of pending jobs and an empty-looking store, so the chain
         * is run inline for the duration of the seed.
         */
        config(['queue.default' => 'sync']);

        $products = $this->seedProducts();
        $completeOrder = app(CompleteOrder::class);

        foreach (self::SHOPPERS as $shopper) {
            $user = $this->seedShopper($shopper, $products, $completeOrder);

            $this->report($user);
        }
    }

    /**
     * Demo data is never appropriate in production, and seeding it against a real
     * provider would send real money to made-up bank accounts.
     */
    private function isSafeToSeed(): bool
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder skipped: refusing to seed demo users in production.');

            return false;
        }

        if (config('payments.default') !== 'fake') {
            $this->command?->warn(sprintf(
                'DemoSeeder skipped: PAYMENTS_GATEWAY is "%s". Set it to "fake" before seeding demo cashbacks.',
                config('payments.default'),
            ));

            return false;
        }

        return true;
    }

    /**
     * @return list<Product>
     */
    private function seedProducts(): array
    {
        return array_map(
            fn (array $product): Product => Product::query()->updateOrCreate(
                ['name' => $product['name']],
                ['price_minor' => $product['price_minor'], 'currency' => 'NGN'],
            ),
            self::PRODUCTS,
        );
    }

    /**
     * @param  array{name: string, email: string, completed: int, products: int, days: int, pending: int, cancelled: int, bank: bool}  $shopper
     * @param  list<Product>  $products
     */
    private function seedShopper(array $shopper, array $products, CompleteOrder $completeOrder): User
    {
        $user = User::factory()->create([
            'name' => $shopper['name'],
            'email' => $shopper['email'],
        ]);

        if ($shopper['bank']) {
            PayoutAccount::factory()->default()->for($user)->create(['account_name' => $shopper['name']]);
        }

        for ($index = 0; $index < $shopper['completed']; $index++) {
            $completeOrder->handle($this->order(
                $user,
                $products[$index % $shopper['products']],
                $index % $shopper['days'],
            ));
        }

        $this->seedUnfinishedOrders($user, $products[0], $shopper['pending'], OrderStatus::Pending);
        $this->seedUnfinishedOrders($user, $products[0], $shopper['cancelled'], OrderStatus::Cancelled);

        return $user;
    }

    /**
     * Orders that deliberately do not count toward anything.
     */
    private function seedUnfinishedOrders(User $user, Product $product, int $count, OrderStatus $status): void
    {
        if ($count < 1) {
            return;
        }

        foreach (range(1, $count) as $index) {
            $this->order($user, $product, $index)->forceFill(['status' => $status])->save();
        }
    }

    private function order(User $user, Product $product, int $daysAgo): Order
    {
        $quantity = ($daysAgo % 3) + 1;

        return Order::factory()->for($user)->for($product)->create([
            'quantity' => $quantity,
            'amount_minor' => $product->price_minor * $quantity,
            'placed_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * Print what the chain actually produced, so the outcome of seeding is visible
     * without querying anything.
     */
    private function report(User $user): void
    {
        $this->command?->line(sprintf(
            '  %-18s %2d purchases  %2d achievements  %-12s  %d cashback(s)',
            $user->name,
            $user->orders()->completed()->count(),
            $user->achievements()->count(),
            $user->badges()->orderByDesc('threshold')->first()?->badge_name ?? '—',
            $user->cashbacks()->count(),
        ));
    }
}
