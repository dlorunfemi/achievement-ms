<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cashback\Actions\PayBadgeCashback;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashbackController extends Controller
{
    /**
     * Payouts, filterable by status.
     *
     * The case this exists for is `?status=processing`: a transfer the provider
     * accepted and never called back about sits there indefinitely, and until now
     * there was no way to see it short of querying the database by hand.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(PayoutStatus::class)],
            'stale_minutes' => ['sometimes', 'integer', 'min:0'],
        ]);

        $cashbacks = Cashback::query()
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['stale_minutes']),
                fn ($query) => $query->where('updated_at', '<=', now()->subMinutes((int) $filters['stale_minutes'])),
            )
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'gateway' => config('payments.default'),
            'count' => $cashbacks->count(),
            'total_minor' => $cashbacks->sum('amount_minor'),
            'cashbacks' => $cashbacks->map(fn (Cashback $cashback): array => $this->present($cashback))->all(),
        ]);
    }

    /**
     * Re-drive one payout.
     *
     * Refused for a payout in Processing, and that refusal is the point: the provider
     * has the instruction and is settling it out of band, so re-sending is how a user
     * gets paid twice. Those rows are resolved by a webhook, not by this.
     */
    public function retry(Cashback $cashback, PayBadgeCashback $payBadgeCashback): JsonResponse
    {
        if ($cashback->status === PayoutStatus::Paid) {
            return response()->json([
                'message' => 'Already paid.',
                'cashback' => $this->present($cashback),
            ]);
        }

        if ($cashback->status === PayoutStatus::Processing) {
            return response()->json([
                'message' => 'This transfer is in flight with the provider. Re-sending it risks paying twice — it settles by webhook.',
                'cashback' => $this->present($cashback),
            ], 409);
        }

        $userBadge = $cashback->userBadge;

        if ($userBadge === null) {
            return response()->json([
                'message' => 'The badge behind this payout no longer exists, so there is nothing to re-drive.',
            ], 422);
        }

        return response()->json([
            'cashback' => $this->present($payBadgeCashback->handle($userBadge)->refresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Cashback $cashback): array
    {
        return [
            'id' => $cashback->getKey(),
            'user_id' => $cashback->user_id,
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
            'updated_at' => $cashback->updated_at?->toIso8601String(),
        ];
    }
}
