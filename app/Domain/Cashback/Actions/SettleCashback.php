<?php

namespace App\Domain\Cashback\Actions;

use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Events\CashbackFailed;
use App\Domain\Cashback\Events\CashbackPaid;
use App\Domain\Cashback\Models\Cashback;
use App\Payments\ValueObjects\TransferUpdate;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a payout the provider settled out of band.
 *
 * PayBadgeCashback leaves an accepted-but-unsettled transfer in Processing and
 * deliberately never re-sends it. This is the other end of that: the provider's
 * callback is what finally moves the row to Paid or Failed.
 *
 * Providers redeliver callbacks freely — on their own retry schedule, and again if we
 * are slow to answer — so this is idempotent by construction: an already-terminal
 * payout is returned untouched and fires no second event.
 */
final class SettleCashback
{
    /**
     * @return Cashback|null The payout that moved, or null when the reference is not
     *                       one of ours or the payout had already settled.
     */
    public function handle(TransferUpdate $update): ?Cashback
    {
        if (! $update->settled()) {
            return null;
        }

        return DB::transaction(function () use ($update): ?Cashback {
            // Locked because a provider retry and our own poll could otherwise both
            // observe Processing and both dispatch a settlement event.
            $cashback = Cashback::query()
                ->where('idempotency_key', $update->reference)
                ->lockForUpdate()
                ->first();

            if ($cashback === null || ! $this->isNews($cashback, $update)) {
                return null;
            }

            return $update->successful()
                ? $this->markPaid($cashback, $update)
                : $this->markFailed($cashback, $update);
        });
    }

    /**
     * Whether this callback actually changes anything.
     *
     * Paid is terminal and nothing supersedes it. A failure reported against a payout
     * already marked Failed is a redelivery. A success against a Failed payout is not
     * — the money moved after all, and the row must catch up.
     */
    private function isNews(Cashback $cashback, TransferUpdate $update): bool
    {
        if ($cashback->status === PayoutStatus::Paid) {
            return false;
        }

        return ! ($update->failed() && $cashback->status === PayoutStatus::Failed);
    }

    private function markPaid(Cashback $cashback, TransferUpdate $update): Cashback
    {
        $cashback->forceFill([
            'status' => PayoutStatus::Paid,
            'gateway_reference' => $update->providerReference ?? $cashback->gateway_reference,
            'failure_reason' => null,
            'paid_at' => now(),
        ])->save();

        CashbackPaid::dispatch($cashback);

        return $cashback;
    }

    private function markFailed(Cashback $cashback, TransferUpdate $update): Cashback
    {
        $cashback->forceFill([
            'status' => PayoutStatus::Failed,
            'gateway_reference' => $update->providerReference ?? $cashback->gateway_reference,
            'failure_reason' => $update->failureReason,
        ])->save();

        CashbackFailed::dispatch($cashback);

        return $cashback;
    }
}
