<?php

namespace App\Domain\Achievements\Metrics;

use App\Domain\Achievements\Contracts\ProgressMetric;
use App\Models\User;

/**
 * Scores what a user has actually spent, in whole naira.
 *
 * Thresholds are stored in major units so a catalog row reads the way the achievement
 * is named ("₦250,000 Spent" is a threshold of 250000). The kobo remainder is dropped
 * rather than rounded up: a user is never handed a tier they have not paid for.
 */
final class TotalSpendMetric implements ProgressMetric
{
    public function groupKey(): string
    {
        return 'spend';
    }

    public function currentValueFor(User $user): int
    {
        return intdiv((int) $user->orders()->completed()->sum('amount_minor'), 100);
    }
}
