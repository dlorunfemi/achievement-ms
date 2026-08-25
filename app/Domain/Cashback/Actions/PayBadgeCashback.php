<?php

namespace App\Domain\Cashback\Actions;

use App\Domain\Achievements\Models\UserBadge;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Events\CashbackFailed;
use App\Domain\Cashback\Events\CashbackPaid;
use App\Domain\Cashback\Models\Cashback;
use App\Domain\Cashback\Models\PayoutAccount;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Exceptions\MissingPayoutAccountException;
use App\Payments\ValueObjects\Money;
use App\Payments\ValueObjects\RecipientRegistration;
use App\Payments\ValueObjects\TransferRequest;
use Illuminate\Support\Facades\DB;

/**
 * Pays the reward owed for one unlocked badge.
 *
 * The cashback row is written before the provider is called, keyed by the user badge,
 * so a retried job resumes the existing record rather than creating a second one. A
 * record already marked Paid short-circuits, which makes the whole action safe to run
 * as many times as the queue decides to.
 */
final class PayBadgeCashback
{
    /**
     * Create a new class instance.
     */
    public function __construct(private PaymentGateway $gateway)
    {
        //
    }

    public function handle(UserBadge $userBadge): Cashback
    {
        $cashback = $this->openPayout($userBadge);

        // Paid is terminal. Pending means the provider already has the instruction
        // and is settling it; re-sending would risk a duplicate transfer.
        if (in_array($cashback->status, [PayoutStatus::Paid, PayoutStatus::Processing], true)) {
            return $cashback;
        }

        $account = $userBadge->user->defaultPayoutAccount();

        if ($account === null) {
            return $this->recordFailure(
                $cashback,
                MissingPayoutAccountException::forUser($userBadge->user)->getMessage(),
            );
        }

        $registration = $this->registerRecipient($account);

        // The provider would not take the account. Nothing has been sent, so this is
        // an ordinary failure the retry sweep can pick up once the details are fixed.
        if ($registration->failed()) {
            return $this->recordFailure($cashback, $registration->failureReason);
        }

        $cashback->forceFill([
            'status' => PayoutStatus::Processing,
            'attempts' => $cashback->attempts + 1,
        ])->save();

        $result = $this->gateway->transfer(new TransferRequest(
            amount: $this->reward(),
            recipient: $account->toRecipientAccount()->withProviderToken($registration->token),
            reference: $cashback->idempotency_key,
            narration: (string) config('cashback.narration'),
        ));

        if ($result->successful()) {
            $cashback->forceFill([
                'status' => PayoutStatus::Paid,
                'gateway_reference' => $result->reference,
                'failure_reason' => null,
                'paid_at' => now(),
            ])->save();

            CashbackPaid::dispatch($cashback);

            return $cashback;
        }

        // A pending transfer is not a failure: the provider accepted it and will
        // settle out of band, so the record stays in Processing awaiting a webhook.
        if ($result->pendingSettlement()) {
            $cashback->forceFill(['gateway_reference' => $result->reference])->save();

            return $cashback;
        }

        return $this->recordFailure($cashback, $result->failureReason, $result->reference);
    }

    /**
     * Make sure the provider will accept this account, and remember whatever it
     * issued so a user's second badge costs one call to the provider rather than two.
     */
    private function registerRecipient(PayoutAccount $account): RecipientRegistration
    {
        $gateway = $this->gateway->name();
        $token = $account->recipientTokenFor($gateway);

        if ($token !== null) {
            return RecipientRegistration::registered($token);
        }

        $registration = $this->gateway->ensureRecipient($account->toRecipientAccount());

        if ($registration->token !== null) {
            $account->rememberRecipientToken($gateway, $registration->token);
        }

        return $registration;
    }

    private function recordFailure(Cashback $cashback, ?string $reason, ?string $reference = null): Cashback
    {
        $cashback->forceFill([
            'status' => PayoutStatus::Failed,
            'failure_reason' => $reason,
            'gateway_reference' => $reference ?? $cashback->gateway_reference,
        ])->save();

        CashbackFailed::dispatch($cashback);

        return $cashback;
    }

    /**
     * The reward every badge is worth. Configured rather than hardcoded so the amount
     * can change without touching the domain.
     */
    private function reward(): Money
    {
        return Money::ofMinorUnits(
            (int) config('cashback.badge_reward_minor'),
            (string) config('cashback.currency'),
        );
    }

    /**
     * Find or open the payout record for this badge. firstOrCreate plus the unique
     * index on idempotency_key means a concurrent attempt can never open a second row.
     */
    private function openPayout(UserBadge $userBadge): Cashback
    {
        $reward = $this->reward();

        return DB::transaction(fn (): Cashback => Cashback::query()->firstOrCreate(
            ['idempotency_key' => $this->idempotencyKeyFor($userBadge)],
            [
                'user_badge_id' => $userBadge->getKey(),
                'user_id' => $userBadge->user_id,
                'amount_minor' => $reward->minorUnits,
                'currency' => $reward->currency,
                'status' => PayoutStatus::Pending,
                'gateway' => $this->gateway->name(),
            ],
        ));
    }

    private function idempotencyKeyFor(UserBadge $userBadge): string
    {
        return 'cashback:user-badge:'.$userBadge->getKey();
    }
}
