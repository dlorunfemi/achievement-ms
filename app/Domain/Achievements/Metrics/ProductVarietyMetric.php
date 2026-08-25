<?php

namespace App\Domain\Achievements\Metrics;

use App\Domain\Achievements\Contracts\ProgressMetric;
use App\Models\User;

/**
 * Scores how far a user has explored the catalog, counting distinct products bought
 * rather than orders placed. Ten repeat orders of the same item is loyalty, not
 * variety, and the purchases group already rewards that.
 */
final class ProductVarietyMetric implements ProgressMetric
{
    public function groupKey(): string
    {
        return 'variety';
    }

    public function currentValueFor(User $user): int
    {
        return $user->orders()->completed()->distinct()->count('product_id');
    }
}
