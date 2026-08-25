<?php

namespace App\Domain\Achievements\Metrics;

use App\Domain\Achievements\Contracts\ProgressMetric;
use App\Models\User;

/**
 * Counts the purchases a user has actually completed. Pending and cancelled orders
 * deliberately do not move the user toward an achievement.
 */
final class PurchaseCountMetric implements ProgressMetric
{
    public function groupKey(): string
    {
        return 'purchases';
    }

    public function currentValueFor(User $user): int
    {
        return $user->orders()->completed()->count();
    }
}
