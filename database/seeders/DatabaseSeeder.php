<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The catalogs are reference data: every environment needs them.
        $this->call([
            AchievementSeeder::class,
            BadgeSeeder::class,
        ]);

        // A store to look at. DemoSeeder guards itself, but there is no reason to
        // even reach for it on a production deploy.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
