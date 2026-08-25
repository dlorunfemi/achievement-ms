<?php

namespace App\Providers;

use App\Domain\Achievements\Actions\EvaluateAchievementProgress;
use App\Domain\Achievements\Actions\ListScorableGroups;
use App\Domain\Achievements\Contracts\ProgressMetric;
use App\Domain\Achievements\Metrics\ProductVarietyMetric;
use App\Domain\Achievements\Metrics\PurchaseCountMetric;
use App\Domain\Achievements\Metrics\PurchaseDaysMetric;
use App\Domain\Achievements\Metrics\TotalSpendMetric;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Every achievement group is backed by one metric. Registering a new progression
     * is a matter of adding its class here and seeding the catalog rows.
     *
     * @var list<class-string<ProgressMetric>>
     */
    private const PROGRESS_METRICS = [
        PurchaseCountMetric::class,
        TotalSpendMetric::class,
        ProductVarietyMetric::class,
        PurchaseDaysMetric::class,
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->tag(self::PROGRESS_METRICS, ProgressMetric::class);

        $this->app->bind(
            EvaluateAchievementProgress::class,
            fn (Application $app): EvaluateAchievementProgress => new EvaluateAchievementProgress(
                $app->tagged(ProgressMetric::class),
            ),
        );

        $this->app->bind(
            ListScorableGroups::class,
            fn (Application $app): ListScorableGroups => new ListScorableGroups(
                $app->tagged(ProgressMetric::class),
            ),
        );
    }
}
