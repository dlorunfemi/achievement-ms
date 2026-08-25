<?php

use App\Domain\Achievements\Actions\BuildUserProgression;
use App\Domain\Achievements\Actions\EvaluateAchievementProgress;
use App\Domain\Achievements\Events\BadgeUnlocked;
use App\Domain\Achievements\Models\Achievement;
use App\Domain\Achievements\Models\Badge;
use App\Domain\Achievements\Models\UserAchievement;
use App\Models\User;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\BadgeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed([AchievementSeeder::class, BadgeSeeder::class]);
    $this->user = User::factory()->create();
});

/**
 * Award $count achievements directly, so badge thresholds can be tested without
 * threading them through the purchase count.
 */
function grantAchievements(User $user, int $count): void
{
    Achievement::query()->inProgressionOrder()->take($count)->get()
        ->each(fn (Achievement $achievement) => UserAchievement::factory()
            ->for($user)
            ->fromCatalog($achievement)
            ->create());
}

it('awards no badge to a user who has unlocked nothing', function () {
    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->badges()->count())->toBe(0);
});

it('awards the starter badge on the first achievement', function () {
    grantAchievements($this->user, 1);

    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->badges()->pluck('badge_name')->all())->toBe(['Beginner']);
});

it('awards each badge once its achievement threshold is reached', function (int $achievements, array $expected) {
    grantAchievements($this->user, $achievements);

    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->badges()->orderBy('threshold')->pluck('badge_name')->all())->toBe($expected);
})->with([
    [0, []],
    [3, ['Beginner']],
    [4, ['Beginner', 'Intermediate']],
    [5, ['Beginner', 'Intermediate']],
]);

it('awards every badge earned at once when a user catches up', function () {
    grantAchievements($this->user, 8);

    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->badges()->orderBy('threshold')->pluck('badge_name')->all())
        ->toBe(['Beginner', 'Intermediate', 'Advanced']);
});

it('matches the worked example in the brief: five achievements, three short of advanced', function () {
    grantAchievements($this->user, 5);
    app(EvaluateAchievementProgress::class)->handle($this->user);

    $progression = app(BuildUserProgression::class)->handle($this->user);

    expect($progression->currentBadge)->toBe('Intermediate')
        ->and($progression->nextBadge)->toBe('Advanced')
        ->and($progression->remainingToUnlockNextBadge)->toBe(3);
});

it('reports the highest badge held as the current badge', function () {
    grantAchievements($this->user, 4);
    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect(app(BuildUserProgression::class)->handle($this->user)->currentBadge)->toBe('Intermediate');
});

it('has no next badge once every badge is held', function () {
    grantAchievements($this->user, Achievement::query()->count());
    app(EvaluateAchievementProgress::class)->handle($this->user);

    $progression = app(BuildUserProgression::class)->handle($this->user);

    expect($progression->currentBadge)->toBe('Legend')
        ->and($progression->nextBadge)->toBeNull()
        ->and($progression->remainingToUnlockNextBadge)->toBe(0);
});

it('snapshots the badge so later catalog edits do not rewrite history', function () {
    grantAchievements($this->user, 4);
    app(EvaluateAchievementProgress::class)->handle($this->user);

    Badge::query()->where('key', 'intermediate')->update(['name' => 'Renamed', 'threshold' => 99]);

    expect($this->user->badges()->where('badge_key', 'intermediate')->first())
        ->badge_name->toBe('Intermediate')
        ->threshold->toBe(4);
});

it('fires a badge unlocked event carrying the name and the user', function () {
    Event::fake([BadgeUnlocked::class]);
    grantAchievements($this->user, 4);

    app(EvaluateAchievementProgress::class)->handle($this->user);

    Event::assertDispatched(BadgeUnlocked::class, function (BadgeUnlocked $event) {
        return $event->badge_name === 'Intermediate'
            && $event->user->is($this->user)
            && $event->userBadge->badge_key === 'intermediate';
    });
});

it('announces every badge earned in one evaluation', function () {
    Event::fake([BadgeUnlocked::class]);
    grantAchievements($this->user, 4);

    app(EvaluateAchievementProgress::class)->handle($this->user);

    Event::assertDispatchedTimes(BadgeUnlocked::class, 2);
});

it('does not re-award or re-announce a badge already held', function () {
    grantAchievements($this->user, 4);
    app(EvaluateAchievementProgress::class)->handle($this->user);

    Event::fake([BadgeUnlocked::class]);
    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->badges()->count())->toBe(2);
    Event::assertNotDispatched(BadgeUnlocked::class);
});

it('refuses to store the same badge twice for one user', function () {
    grantAchievements($this->user, 1);
    app(EvaluateAchievementProgress::class)->handle($this->user);

    $this->user->badges()->create([
        'badge_key' => 'beginner',
        'badge_name' => 'Beginner',
        'threshold' => 0,
        'unlocked_at' => now(),
    ]);
})->throws(UniqueConstraintViolationException::class);

it('counts achievements from every group toward a badge', function () {
    Achievement::factory()->forGroup('referrals', 1, 'First Referral')->create();
    Achievement::factory()->forGroup('referrals', 5, 'Five Referrals')->create();

    grantAchievements($this->user, 4);
    app(EvaluateAchievementProgress::class)->handle($this->user);

    expect($this->user->badges()->count())->toBe(2);
});
