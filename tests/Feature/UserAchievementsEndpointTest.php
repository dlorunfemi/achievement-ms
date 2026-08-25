<?php

use App\Domain\Achievements\Models\Achievement;
use App\Domain\Achievements\Models\UserAchievement;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    fakeGateway();
    $this->user = userWithPayoutAccount();
});

it('is registered in the web routes file at the path the brief specifies', function () {
    expect(route('users.achievements', $this->user, absolute: false))
        ->toBe("/users/{$this->user->getKey()}/achievements");
});

it('returns the five keys the brief names, and nothing else', function () {
    $response = $this->getJson(route('users.achievements', $this->user));

    $response->assertOk();

    expect(array_keys($response->json()))->toBe([
        'unlocked_achievements',
        'next_available_achievements',
        'current_badge',
        'next_badge',
        'remaining_to_unlock_next_badge',
    ]);
});

it('is not wrapped in a data envelope', function () {
    $this->getJson(route('users.achievements', $this->user))
        ->assertJsonMissingPath('data');
});

it('describes a user who has bought nothing yet', function () {
    $this->getJson(route('users.achievements', $this->user))
        ->assertOk()
        ->assertExactJson([
            'unlocked_achievements' => [],
            'next_available_achievements' => [
                'Shopped on 3 Days',
                'First Purchase',
                '₦250,000 Spent',
                '3 Different Products',
            ],
            'current_badge' => null,
            'next_badge' => 'Beginner',
            'remaining_to_unlock_next_badge' => 1,
        ]);
});

it('describes a user after their first purchase', function () {
    completePurchases($this->user, 1);

    $this->getJson(route('users.achievements', $this->user))
        ->assertOk()
        ->assertExactJson([
            'unlocked_achievements' => ['First Purchase'],
            'next_available_achievements' => [
                'Shopped on 3 Days',
                '5 Purchases',
                '₦250,000 Spent',
                '3 Different Products',
            ],
            'current_badge' => 'Beginner',
            'next_badge' => 'Intermediate',
            'remaining_to_unlock_next_badge' => 3,
        ]);
});

it('matches the worked example in the brief', function () {
    completePurchases($this->user, 5);

    $this->getJson(route('users.achievements', $this->user))
        ->assertOk()
        ->assertExactJson([
            'unlocked_achievements' => ['First Purchase', '5 Purchases'],
            'next_available_achievements' => [
                'Shopped on 3 Days',
                '10 Purchases',
                '₦250,000 Spent',
                '3 Different Products',
            ],
            'current_badge' => 'Beginner',
            'next_badge' => 'Intermediate',
            'remaining_to_unlock_next_badge' => 2,
        ]);
});

it('returns only the next achievement of each group', function () {
    Achievement::factory()->forGroup('referrals', 1, 'First Referral')->create();
    Achievement::factory()->forGroup('referrals', 5, 'Five Referrals')->create();
    Achievement::factory()->forGroup('referrals', 10, 'Ten Referrals')->create();

    completePurchases($this->user, 1);

    $this->getJson(route('users.achievements', $this->user))
        ->assertOk()
        ->assertJsonPath('next_available_achievements', [
            'Shopped on 3 Days',
            '5 Purchases',
            'First Referral',
            '₦250,000 Spent',
            '3 Different Products',
        ]);
});

it('reports the highest badge held as the current badge', function () {
    completePurchases($this->user, 1);
    Achievement::factory()->count(3)->create();
    completePurchases($this->user, 4);

    $this->getJson(route('users.achievements', $this->user))
        ->assertOk()
        ->assertJsonPath('current_badge', 'Beginner')
        ->assertJsonPath('next_badge', 'Intermediate');
});

it('offers no further achievements once every one is unlocked', function () {
    $catalog = Achievement::query()->inProgressionOrder()->get();

    $catalog->each(fn (Achievement $achievement) => UserAchievement::factory()
        ->for($this->user)
        ->fromCatalog($achievement)
        ->create());

    $this->getJson(route('users.achievements', $this->user))
        ->assertOk()
        ->assertJsonPath('next_available_achievements', [])
        ->assertJsonCount($catalog->count(), 'unlocked_achievements');
});

it('types every field as the brief specifies', function () {
    completePurchases($this->user, 1);

    $this->getJson(route('users.achievements', $this->user))
        ->assertOk()
        ->assertJsonStructure([
            'unlocked_achievements',
            'next_available_achievements',
            'current_badge',
            'next_badge',
            'remaining_to_unlock_next_badge',
        ]);

    $body = $this->getJson(route('users.achievements', $this->user))->json();

    expect($body['unlocked_achievements'])->toBeArray()
        ->and($body['next_available_achievements'])->toBeArray()
        ->and($body['current_badge'])->toBeString()
        ->and($body['next_badge'])->toBeString()
        ->and($body['remaining_to_unlock_next_badge'])->toBeInt();
});

it('returns lists, not objects keyed by index, once entries are skipped', function () {
    completePurchases($this->user, 5);

    $raw = $this->getJson(route('users.achievements', $this->user))->getContent();

    expect($raw)->toContain('"unlocked_achievements":["First Purchase","5 Purchases"]');
});

it('404s for a user that does not exist', function () {
    $this->getJson('/users/999999/achievements')->assertNotFound();
});

it('404s in json even for a caller that sent no accept header', function () {
    // The route lives in routes/web.php because the brief puts it there, not because
    // it serves pages: a plain curl must not get back an HTML error page.
    $response = $this->get('/users/999999/achievements');

    $response->assertNotFound();

    expect($response->headers->get('content-type'))->toContain('application/json');
});

it('is rate limited, because it is unauthenticated', function () {
    expect(Route::getRoutes()->getByName('users.achievements')->gatherMiddleware())
        ->toContain('throttle:60,1');
});

it('never leaks another user\'s progress', function () {
    completePurchases($this->user, 5);
    $other = User::factory()->create();

    $this->getJson(route('users.achievements', $other))
        ->assertOk()
        ->assertJsonPath('unlocked_achievements', []);
});

it('does not change any state', function () {
    completePurchases($this->user, 1);
    $before = $this->user->achievements()->count();

    $this->getJson(route('users.achievements', $this->user))->assertOk();
    $this->getJson(route('users.achievements', $this->user))->assertOk();

    expect($this->user->achievements()->count())->toBe($before);
});
