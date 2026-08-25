<?php

namespace Database\Seeders;

use App\Domain\Achievements\Models\Badge;
use Illuminate\Database\Seeder;

/**
 * The badge catalog, scored on the total number of achievements a user holds.
 *
 * Thresholds follow the worked example in the brief: a user holding 5 achievements
 * needs 3 more to reach Advanced, so Advanced sits at 8. The tiers above Master were
 * added with the spend, variety and loyalty groups — Legend at 16 is the whole
 * catalog, so the top of the ladder is reachable rather than decorative.
 */
class BadgeSeeder extends Seeder
{
    /**
     * @var list<array{key: string, name: string, threshold: int}>
     */
    private const CATALOG = [
        ['key' => 'beginner', 'name' => 'Beginner', 'threshold' => 1],
        ['key' => 'intermediate', 'name' => 'Intermediate', 'threshold' => 4],
        ['key' => 'advanced', 'name' => 'Advanced', 'threshold' => 8],
        ['key' => 'master', 'name' => 'Master', 'threshold' => 10],
        ['key' => 'champion', 'name' => 'Champion', 'threshold' => 13],
        ['key' => 'legend', 'name' => 'Legend', 'threshold' => 16],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $entry) {
            Badge::query()->updateOrCreate(['key' => $entry['key']], $entry);
        }
    }
}
