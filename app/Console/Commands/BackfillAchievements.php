<?php

namespace App\Console\Commands;

use App\Domain\Achievements\Jobs\BackfillAchievementProgress;
use App\Domain\Achievements\Models\UserAchievement;
use App\Domain\Achievements\Models\UserBadge;
use Illuminate\Console\Command;

class BackfillAchievements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'achievements:backfill
                            {--queue : Hand the work to the queue instead of running it here}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant every achievement and badge users already qualify for';

    /**
     * Execute the console command.
     *
     * The counterpart to seeding a new rung. Unlocking normally happens on a purchase,
     * so without this a catalog addition only reaches a user the next time they buy
     * something — which is arbitrary, and invisible to anyone who never buys again.
     *
     * Progress evaluation is level-based and idempotent, so running this twice grants
     * nothing twice. Every unlock takes the ordinary event path, which means a badge
     * crossed here pays real cashback.
     */
    public function handle(): int
    {
        if ($this->option('queue')) {
            BackfillAchievementProgress::dispatch();

            $this->info('Backfill queued. Watch the worker for unlocks and payouts.');

            return self::SUCCESS;
        }

        $achievementsBefore = UserAchievement::query()->count();
        $badgesBefore = UserBadge::query()->count();

        $this->info('Backfilling every user against the current catalog…');

        BackfillAchievementProgress::dispatchSync();

        $achievements = UserAchievement::query()->count() - $achievementsBefore;
        $badges = UserBadge::query()->count() - $badgesBefore;

        $this->info($achievements === 0 && $badges === 0
            ? 'Everyone was already up to date.'
            : "Granted {$achievements} achievement(s) and {$badges} badge(s).");

        return self::SUCCESS;
    }
}
