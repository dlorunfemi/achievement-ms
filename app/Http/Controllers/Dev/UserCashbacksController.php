<?php

namespace App\Http\Controllers\Dev;

use App\Domain\Achievements\Models\UserBadge;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserCashbacksController extends Controller
{
    /**
     * The payout side of the flow, which the graded endpoint deliberately does not
     * expose: every badge the user has unlocked and the ₦300 transfer it triggered.
     */
    public function __invoke(User $user): JsonResponse
    {
        $badges = $user->badges()->orderBy('unlocked_at')->orderBy('id')->get();
        $cashbacks = $user->cashbacks()->orderBy('id')->get();

        return response()->json([
            'gateway' => config('payments.default'),
            'payout_account' => $user->defaultPayoutAccount()?->only([
                'bank_code', 'bank_name', 'account_number', 'account_name',
            ]),
            'unlocked_badges' => $badges->map(fn (UserBadge $badge): array => [
                'badge_name' => $badge->badge_name,
                'threshold' => $badge->threshold,
                'unlocked_at' => $badge->unlocked_at?->toIso8601String(),
            ])->all(),
            'cashbacks' => $cashbacks->map(fn (Cashback $cashback): array => [
                'id' => $cashback->getKey(),
                'user_badge_id' => $cashback->user_badge_id,
                'amount_minor' => $cashback->amount_minor,
                'currency' => $cashback->currency,
                'status' => $cashback->status->value,
                'gateway' => $cashback->gateway,
                'gateway_reference' => $cashback->gateway_reference,
                'idempotency_key' => $cashback->idempotency_key,
                'failure_reason' => $cashback->failure_reason,
                'attempts' => $cashback->attempts,
                'paid_at' => $cashback->paid_at?->toIso8601String(),
            ])->all(),
            'total_paid_minor' => $cashbacks->where('status', PayoutStatus::Paid)->sum('amount_minor'),
        ]);
    }
}
