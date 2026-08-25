<?php

namespace Database\Seeders;

use App\Domain\Achievements\Models\Achievement;
use Illuminate\Database\Seeder;

/**
 * The achievement catalog.
 *
 * Progressions live in data, not in conditionals. Adding a rung is a row here; adding
 * a whole new group additionally needs a ProgressMetric registered in
 * DomainServiceProvider that knows how to count it.
 */
class AchievementSeeder extends Seeder
{
    /**
     * @var list<array{group_key: string, threshold: int, name: string}>
     */
    private const CATALOG = [
        // Orders completed, scored by PurchaseCountMetric.
        ['group_key' => 'purchases', 'threshold' => 1, 'name' => 'First Purchase'],
        ['group_key' => 'purchases', 'threshold' => 5, 'name' => '5 Purchases'],
        ['group_key' => 'purchases', 'threshold' => 10, 'name' => '10 Purchases'],
        ['group_key' => 'purchases', 'threshold' => 25, 'name' => '25 Purchases'],
        ['group_key' => 'purchases', 'threshold' => 50, 'name' => '50 Purchases'],
        ['group_key' => 'purchases', 'threshold' => 100, 'name' => '100 Purchases'],

        // Naira spent, scored by TotalSpendMetric. Thresholds are major units.
        ['group_key' => 'spend', 'threshold' => 250_000, 'name' => '₦250,000 Spent'],
        ['group_key' => 'spend', 'threshold' => 1_000_000, 'name' => '₦1,000,000 Spent'],
        ['group_key' => 'spend', 'threshold' => 2_500_000, 'name' => '₦2,500,000 Spent'],
        ['group_key' => 'spend', 'threshold' => 10_000_000, 'name' => '₦10,000,000 Spent'],

        // Distinct products bought, scored by ProductVarietyMetric.
        ['group_key' => 'variety', 'threshold' => 3, 'name' => '3 Different Products'],
        ['group_key' => 'variety', 'threshold' => 10, 'name' => '10 Different Products'],
        ['group_key' => 'variety', 'threshold' => 25, 'name' => '25 Different Products'],

        // Distinct days shopped, scored by PurchaseDaysMetric.
        ['group_key' => 'loyalty', 'threshold' => 3, 'name' => 'Shopped on 3 Days'],
        ['group_key' => 'loyalty', 'threshold' => 7, 'name' => 'Shopped on 7 Days'],
        ['group_key' => 'loyalty', 'threshold' => 30, 'name' => 'Shopped on 30 Days'],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $entry) {
            Achievement::query()->updateOrCreate(
                ['key' => $entry['group_key'].'.'.$entry['threshold']],
                [
                    'name' => $entry['name'],
                    'group_key' => $entry['group_key'],
                    'threshold' => $entry['threshold'],
                ],
            );
        }
    }
}
