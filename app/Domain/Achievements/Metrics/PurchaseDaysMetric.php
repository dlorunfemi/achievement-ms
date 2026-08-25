<?php

namespace App\Domain\Achievements\Metrics;

use App\Domain\Achievements\Contracts\ProgressMetric;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Scores habit rather than volume: the number of distinct days on which a user has
 * completed a purchase. A single spree of thirty orders is one day; coming back
 * thirty times is thirty.
 */
final class PurchaseDaysMetric implements ProgressMetric
{
    public function groupKey(): string
    {
        return 'loyalty';
    }

    public function currentValueFor(User $user): int
    {
        return $user->orders()->completed()->distinct()->count(DB::raw('date(placed_at)'));
    }
}
