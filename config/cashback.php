<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Badge Reward
    |--------------------------------------------------------------------------
    |
    | Paid to a user each time they unlock a badge, in minor units. The assessment
    | specifies ₦300, which is 30000 kobo.
    |
    | The provider that moves the money is configured separately, in config/payments.php.
    |
    */

    'badge_reward_minor' => env('CASHBACK_BADGE_REWARD_MINOR', 30_000),

    'currency' => env('CASHBACK_CURRENCY', 'NGN'),

    'narration' => env('CASHBACK_NARRATION', 'Badge cashback reward'),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | A transfer a provider accepted but has not settled sits in Processing waiting
    | for a callback. When that callback never arrives, cashbacks:reconcile asks the
    | provider directly. The grace period is how long a payout is left alone first,
    | so the sweep does not race the webhook for every ordinary transfer.
    |
    */

    'reconcile_after_minutes' => env('CASHBACK_RECONCILE_AFTER_MINUTES', 15),

    'reconcile_batch' => env('CASHBACK_RECONCILE_BATCH', 100),

];
