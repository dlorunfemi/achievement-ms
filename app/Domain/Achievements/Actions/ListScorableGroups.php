<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Contracts\ProgressMetric;

/**
 * The achievement groups the system can actually score.
 *
 * A catalog row whose group_key has no metric behind it is unreachable: nothing
 * measures it, so no user ever unlocks it. This is what lets the admin endpoints
 * refuse such a row at the boundary instead of accepting dead data.
 */
final class ListScorableGroups
{
    /**
     * Create a new class instance.
     *
     * @param  iterable<ProgressMetric>  $metrics
     */
    public function __construct(private iterable $metrics)
    {
        //
    }

    /**
     * @return list<string>
     */
    public function handle(): array
    {
        $groups = [];

        foreach ($this->metrics as $metric) {
            $groups[] = $metric->groupKey();
        }

        sort($groups);

        return array_values(array_unique($groups));
    }
}
