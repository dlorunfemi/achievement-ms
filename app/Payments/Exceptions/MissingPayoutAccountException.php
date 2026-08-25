<?php

namespace App\Payments\Exceptions;

use App\Models\User;

/**
 * Thrown when a payout is owed to a user who has not registered a bank account.
 */
class MissingPayoutAccountException extends PaymentException
{
    public static function forUser(User $user): self
    {
        return new self("User [{$user->getKey()}] has no payout account on file.");
    }
}
