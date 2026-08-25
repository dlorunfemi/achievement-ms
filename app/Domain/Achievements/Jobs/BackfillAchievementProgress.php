<?php

namespace App\Domain\Achievements\Jobs;

use App\Domain\Achievements\Actions\EvaluateAchievementProgress;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings every existing user up to date with a catalog that has just changed.
 *
 * Adding an achievement or a badge is retroactive: a user who has already made 50
 * purchases has earned a new "50 Purchases" rung the moment it exists, and waiting
 * for their next order to notice would be arbitrary.
 *
 * EvaluateAchievementProgress is level-based and idempotent, so this converges rather
 * than double-awarding, and every unlock travels the normal event path — which means
 * a backfill can unlock badges and pay real cashback. That is intended, and it is why
 * the admin endpoints that dispatch it say so in their response.
 */
class BackfillAchievementProgress implements ShouldQueue
{
    use Queueable;

    /**
     * Users are walked in chunks rather than loaded at once: a backfill runs over the
     * whole user table and must not be bounded by memory.
     */
    private const CHUNK_SIZE = 250;

    /**
     * Execute the job.
     */
    public function handle(EvaluateAchievementProgress $evaluateProgress): void
    {
        User::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($users) use ($evaluateProgress): void {
                foreach ($users as $user) {
                    $evaluateProgress->handle($user);
                }
            });
    }
}
