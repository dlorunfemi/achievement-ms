<?php

namespace App\Domain\Cashback\Actions;

use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Events\TransferUpdated;
use App\Payments\PaymentManager;
use InvalidArgumentException;

/**
 * Asks the provider what became of payouts it accepted but never called back about.
 *
 * A transfer left in Processing is money we believe is in flight. Usually a webhook
 * resolves it; when one is lost, dropped by a proxy, or sent while the app was down,
 * the payout would otherwise sit there forever. This is the backstop: poll, and feed
 * the answer through the same TransferUpdated the webhook publishes, so settlement
 * has exactly one implementation.
 *
 * Nothing is re-sent. The only outcome of a sweep is learning what already happened.
 */
final class ReconcilePendingCashbacks
{
    /**
     * Create a new class instance.
     */
    public function __construct(private PaymentManager $payments)
    {
        //
    }

    /**
     * @param  int|null  $olderThanMinutes  Grace period; defaults to the configured one.
     * @return int How many payouts the provider gave a settled answer for.
     */
    public function handle(?int $olderThanMinutes = null): int
    {
        $grace = $olderThanMinutes ?? (int) config('cashback.reconcile_after_minutes');

        $stuck = Cashback::query()
            ->where('status', PayoutStatus::Processing)
            ->where('updated_at', '<=', now()->subMinutes($grace))
            ->orderBy('id')
            ->limit((int) config('cashback.reconcile_batch'))
            ->get();

        $settled = 0;

        foreach ($stuck as $cashback) {
            // The gateway that sent it, not whichever is configured today: a payout
            // made through last month's provider is only that provider's to answer.
            $gateway = $this->gatewayFor($cashback);

            if ($gateway === null) {
                continue;
            }

            // Verified by the reference we generated, which every provider echoes,
            // rather than by their own identifier for the transfer.
            $update = $gateway->verifyTransfer($cashback->idempotency_key);

            if (! $update->settled()) {
                continue;
            }

            TransferUpdated::dispatch($gateway->name(), $update);

            $settled++;
        }

        return $settled;
    }

    /**
     * The gateway a payout was sent through, or null when that provider is no longer
     * configured — a payout we can no longer ask about is left for a human.
     */
    private function gatewayFor(Cashback $cashback): ?PaymentGateway
    {
        try {
            return $this->payments->driver($cashback->gateway);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
