<?php

use App\Domain\Achievements\ValueObjects\Progression;

it('serialises to the shape the achievements endpoint returns', function () {
    $progression = new Progression(
        unlockedAchievements: ['First Purchase', '5 Purchases'],
        nextAvailableAchievements: ['10 Purchases'],
        currentBadge: 'Beginner',
        nextBadge: 'Intermediate',
        remainingToUnlockNextBadge: 2,
    );

    expect($progression->toArray())->toBe([
        'unlocked_achievements' => ['First Purchase', '5 Purchases'],
        'next_available_achievements' => ['10 Purchases'],
        'current_badge' => 'Beginner',
        'next_badge' => 'Intermediate',
        'remaining_to_unlock_next_badge' => 2,
    ]);
});

it('keeps the exact keys the brief names', function () {
    $keys = array_keys((new Progression([], [], null, null, 0))->toArray());

    expect($keys)->toBe([
        'unlocked_achievements',
        'next_available_achievements',
        'current_badge',
        'next_badge',
        'remaining_to_unlock_next_badge',
    ]);
});

it('represents a user with nothing unlocked', function () {
    $progression = new Progression([], ['First Purchase'], null, 'Beginner', 1);

    expect($progression->toArray())
        ->unlocked_achievements->toBe([])
        ->current_badge->toBeNull()
        ->remaining_to_unlock_next_badge->toBe(1);
});
