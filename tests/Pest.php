<?php

use App\Domain\Cashback\Models\PayoutAccount;
use App\Domain\Ordering\Actions\CompleteOrder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Product;
use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Gateways\FakeGateway;
use App\Payments\PaymentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A user with a bank account on file, which is the precondition for any payout.
 */
function userWithPayoutAccount(array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    PayoutAccount::factory()->default()->for($user)->create();

    return $user;
}

/**
 * Complete $count purchases for a user, driving the real Ordering entry point so the
 * whole event chain runs.
 *
 * Every order is the same cheap item bought today, which keeps the spend, variety and
 * loyalty progressions out of reach: a test that asks for "5 purchases" gets exactly
 * the purchases group and nothing incidental.
 */
function completePurchases(User $user, int $count): void
{
    $product = Product::factory()->create();
    $completeOrder = app(CompleteOrder::class);

    foreach (range(1, $count) as $ignored) {
        $completeOrder->handle(
            Order::factory()->worth(10_000)->for($user)->for($product)->create()
        );
    }
}

/**
 * Swap the container's gateway for a FakeGateway the test can assert against.
 *
 * Registered with the manager as well as against the contract, because code that
 * settles an existing payout resolves the gateway by the name stored on it rather
 * than taking whichever one is configured.
 */
function fakeGateway(): FakeGateway
{
    $gateway = new FakeGateway;

    app()->instance(PaymentGateway::class, $gateway);
    app(PaymentManager::class)->extend('fake', fn (): FakeGateway => $gateway);

    return $gateway;
}
