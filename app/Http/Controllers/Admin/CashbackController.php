<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cashback\Actions\PayBadgeCashback;
use App\Domain\Cashback\Enums\PayoutStatus;
use App\Domain\Cashback\Models\Cashback;
use App\Http\Controllers\Controller;
use App\Http\Resources\CashbackResource;
use App\Http\Responses\ErrorCode;
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
            'cashbacks' => CashbackResource::collection($cashbacks),
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
        // Both refusals below are 409: the request was understood and deliberately
        // not acted on because the payout's current state forbids it.
        if ($cashback->status === PayoutStatus::Paid) {
            return ErrorCode::PayoutAlreadyPaid->response(context: [
                'cashback' => new CashbackResource($cashback),
            ]);
        }

        if ($cashback->status === PayoutStatus::Processing) {
            return ErrorCode::PayoutInFlight->response(
                'This transfer is in flight with the provider. Re-sending it risks paying twice — it settles by webhook.',
                ['cashback' => new CashbackResource($cashback)],
            );
        }

        $userBadge = $cashback->userBadge;

        if ($userBadge === null) {
            return ErrorCode::PayoutBadgeMissing->response(
                'The badge behind this payout no longer exists, so there is nothing to re-drive.',
            );
        }

        return response()->json([
            'cashback' => new CashbackResource($payBadgeCashback->handle($userBadge)->refresh()),
        ]);
    }
}
