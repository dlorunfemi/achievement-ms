<?php

namespace App\Domain\Cashback\Actions;

use App\Domain\Cashback\Jobs\RetryFailedCashbacks;
use App\Domain\Cashback\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records the bank account a user should be paid into.
 *
 * Re-registering the same account updates it rather than failing: the table's unique
 * (user_id, bank_code, account_number) makes a duplicate impossible, and a caller
 * correcting a typo in the account name should not have to delete a row first.
 */
final class RegisterPayoutAccount
{
    /**
     * @param  array{bank_code: string, bank_name: string, account_number: string, account_name: string, currency?: string}  $attributes
     */
    public function handle(User $user, array $attributes, bool $makeDefault = true): PayoutAccount
    {
        $account = DB::transaction(function () use ($user, $attributes, $makeDefault): PayoutAccount {
            $account = PayoutAccount::query()->updateOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'bank_code' => $attributes['bank_code'],
                    'account_number' => $attributes['account_number'],
                ],
                [
                    'bank_name' => $attributes['bank_name'],
                    'account_name' => $attributes['account_name'],
                    'currency' => $attributes['currency'] ?? 'NGN',
                    // A user's first account is the default whatever the caller asked
                    // for, or they would have an account on file and still be unpayable.
                    'is_default' => $makeDefault || ! $user->payoutAccounts()->exists(),
                ],
            );

            if ($account->is_default) {
                $user->payoutAccounts()
                    ->whereKeyNot($account->getKey())
                    ->update(['is_default' => false]);
            }

            return $account;
        });

        // A badge unlocked before the user had an account failed with "no payout
        // account" and has been sitting there unpaid ever since. Now it can be paid.
        RetryFailedCashbacks::dispatch($user);

        return $account;
    }
}
