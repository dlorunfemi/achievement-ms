<?php

namespace App\Domain\Cashback\Jobs;

use App\Domain\Cashback\Actions\PayBadgeCashback;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-drives the payouts that failed for a reason that has since been fixed.
 *
 * The motivating case is a user who unlocked a badge before registering a bank
 * account: PayBadgeCashback marked the cashback Failed, and without this nothing
 * would ever pay it. Safe to run at any time — PayBadgeCashback short-circuits on a
 * payout that is already Paid or in flight, and the gateway call is keyed by the
 * cashback's idempotency key.
 */
class RetryFailedCashbacks implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(private User $user)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(PayBadgeCashback $payBadgeCashback): void
    {
        // Nothing to retry into: the user still has nowhere to be paid.
        if ($this->user->defaultPayoutAccount() === null) {
            return;
        }

        $this->user->cashbacks()
            ->where('status', PayoutStatus::Failed)
            ->with('userBadge')
            ->each(function (Cashback $cashback) use ($payBadgeCashback): void {
                if ($cashback->userBadge !== null) {
                    $payBadgeCashback->handle($cashback->userBadge);
                }
            });
    }
}
