<?php

use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use App\Domain\Cashback\Models\PayoutAccount;
use App\Models\User;
use App\Payments\Webhooks\FakeWebhookHandler;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Error Contract
|--------------------------------------------------------------------------
|
| Every non-2xx response in the application answers with the same two keys — a
| sentence for a human and a stable code for a client — plus "errors" on 422 and
| nothing else. The point of testing it here rather than endpoint by endpoint is
| that the contract is enforced in one renderer: a status nobody wrote a
| controller branch for, like 405, has to obey it too.
|
*/

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->gateway = fakeGateway();
});

describe('the shape every error shares', function () {
    it('answers an unknown user with a message and a code', function () {
        $this->getJson('/users/999999/achievements')
            ->assertNotFound()
            ->assertJsonStructure(['message', 'code'])
            ->assertJsonPath('code', 'resource_not_found');
    });

    it('never leaks the model class behind a binding failure', function () {
        $response = $this->getJson('/users/999999/achievements')->assertNotFound();

        expect($response->json('message'))
            ->not->toContain('App\\Models\\User')
            ->not->toContain('No query results');
    });

    it('carries no keys beyond the contract', function () {
        $keys = array_keys($this->getJson('/users/999999/achievements')->json());

        expect($keys)->toEqualCanonicalizing(['message', 'code']);
    });
});

describe('framework faults', function () {
    it('codes a validation failure and keeps the field errors', function () {
        $user = User::factory()->create();

        $this->postJson(route('users.payout-account.store', $user), ['bank_code' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrorFor('bank_code');
    });

    it('codes a method the route does not allow', function () {
        $user = User::factory()->create();

        $this->putJson(route('users.achievements', $user))
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');
    });

    it('codes a throttled caller', function () {
        Route::middleware('throttle:1,1')->get('/test/throttled', fn () => response()->json([]));

        $this->getJson('/test/throttled')->assertOk();
        $this->getJson('/test/throttled')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    });

    it('codes an unhandled exception without exposing it', function () {
        config()->set('app.debug', false);
        Route::get('/test/explodes', fn () => throw new RuntimeException('the database is on fire'));

        $response = $this->getJson('/test/explodes')->assertStatus(500);

        expect($response->json('code'))->toBe('server_error')
            ->and($response->json('message'))->not->toContain('on fire');
    });
});

describe('payment webhooks', function () {
    it('codes a provider it does not recognise', function () {
        $this->postJson(route('webhooks.payments', 'not-a-provider'), [])
            ->assertNotFound()
            ->assertJsonPath('code', 'unknown_payment_provider');
    });

    it('codes a callback it cannot authenticate', function () {
        config()->set('payments.gateways.fake.webhook_secret', 'test-webhook-secret');

        $this->postJson(route('webhooks.payments', 'fake'), ['reference' => 'x'], [
            FakeWebhookHandler::SIGNATURE_HEADER => 'not-the-signature',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_webhook_signature');
    });
});

describe('payout conflicts', function () {
    it('codes a user with no account on file', function () {
        $this->getJson(route('users.payout-account.show', User::factory()->create()))
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');
    });

    it('refuses to re-send an in-flight transfer with a conflict code', function () {
        $this->gateway->alwaysPend();
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $this->postJson(route('admin.cashbacks.retry', $user->cashbacks()->sole()))
            ->assertStatus(409)
            ->assertJsonPath('code', 'payout_in_flight');

        expect($this->gateway->transferCount())->toBe(1);
    });

    it('answers a retry of an already paid payout with a conflict, not an ok', function () {
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $this->postJson(route('admin.cashbacks.retry', $user->cashbacks()->sole()))
            ->assertStatus(409)
            ->assertJsonPath('code', 'payout_already_paid');

        expect($this->gateway->transferCount())->toBe(1);
    });

    /*
     * cashbacks.user_badge_id cascades on delete, so removing the badge takes the
     * payout row with it — the retry cannot find a cashback to act on at all. The
     * controller's PayoutBadgeMissing branch is therefore a type guard rather than a
     * reachable state, and this pins down what a caller actually sees.
     */
    it('answers a retry for a payout whose badge was deleted as a missing resource', function () {
        $this->gateway->alwaysFail();
        $user = userWithPayoutAccount();
        completePurchases($user, 1);

        $cashback = $user->cashbacks()->sole();
        expect($cashback->status)->toBe(PayoutStatus::Failed);

        $cashback->userBadge->delete();

        expect(Cashback::query()->whereKey($cashback->getKey())->exists())->toBeFalse();

        $this->postJson(route('admin.cashbacks.retry', $cashback))
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');
    });
});

describe('success responses are untouched', function () {
    it('leaves the graded endpoint free of any envelope or code', function () {
        $user = User::factory()->create();

        $keys = array_keys($this->getJson(route('users.achievements', $user))->assertOk()->json());

        expect($keys)->toEqualCanonicalizing([
            'unlocked_achievements',
            'next_available_achievements',
            'current_badge',
            'next_badge',
            'remaining_to_unlock_next_badge',
        ]);
    });

    it('leaves a payout account bare', function () {
        $user = User::factory()->create();
        PayoutAccount::factory()->default()->for($user)->create();

        $this->getJson(route('users.payout-account.show', $user))
            ->assertOk()
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('code')
            ->assertJsonPath('is_default', true);
    });
});
