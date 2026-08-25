<?php

namespace App\Domain\Cashback\Actions;

use App\Domain\Achievements\Models\UserBadge;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Events\CashbackFailed;
use App\Domain\Cashback\Models\Cashback;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes the book on a payout whose job has exhausted its retries.
 *
 * Without this, a listener that keeps throwing ends in failed_jobs while the cashback
 * row sits in whatever state the last attempt left it — owed to the user, invisible to
 * everything that looks at cashbacks, and waiting for someone to notice by hand.
 *
 * A payout already in Processing is deliberately left alone: the provider has the
 * instruction and may well have paid it, so marking it Failed would report money that
 * did move as never sent. Those rows belong to cashbacks:reconcile, which asks the
 * provider what actually happened.
 */
final class AbandonCashback
{
    public function handle(UserBadge $userBadge, Throwable $exception): ?Cashback
    {
        $cashback = Cashback::query()
            ->where('user_badge_id', $userBadge->getKey())
            ->first();

        Log::error('Cashback job gave up after its final attempt.', [
            'user_id' => $userBadge->user_id,
            'user_badge_id' => $userBadge->getKey(),
            'badge_key' => $userBadge->badge_key,
            'cashback_id' => $cashback?->getKey(),
            'cashback_status' => $cashback?->status->value,
            'exception' => $exception->getMessage(),
        ]);

        // The job died before it could even open a payout row. There is nothing owed
        // in the database, and the next BadgeUnlocked replay starts cleanly.
        if ($cashback === null) {
            return null;
        }

        if (in_array($cashback->status, [PayoutStatus::Paid, PayoutStatus::Processing], true)) {
            return $cashback;
        }

        $cashback->forceFill([
            'status' => PayoutStatus::Failed,
            'failure_reason' => $exception->getMessage(),
        ])->save();

        CashbackFailed::dispatch($cashback);

        return $cashback;
    }
}
