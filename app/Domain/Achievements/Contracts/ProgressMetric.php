<?php

namespace App\Domain\Achievements\Contracts;

use App\Models\User;

/**
 * A countable measure of user activity that an achievement group is scored against.
 *
 * Every achievement group in the catalog is backed by exactly one metric, matched on
 * group key. Adding a whole new progression (e.g. "reviews") means writing one metric
 * class and seeding catalog rows — no change to the unlock logic.
 */
interface ProgressMetric
{
    /**
     * The achievements.group_key this metric scores.
     */
    public function groupKey(): string;

    /**
     * The user's current standing against this metric.
     */
    public function currentValueFor(User $user): int;
}
