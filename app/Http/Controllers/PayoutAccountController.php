<?php

namespace App\Http\Controllers;

use App\Domain\Cashback\Actions\RegisterPayoutAccount;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Responses\ErrorCode;
use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayoutAccountController extends Controller
{
    /**
     * The account this user is currently paid into.
     */
    public function show(User $user): JsonResponse
    {
        $account = $user->defaultPayoutAccount();

        if ($account === null) {
            return ErrorCode::ResourceNotFound->response('This user has no payout account on file.');
        }

        return (new PayoutAccountResource($account))->response();
    }

    /**
     * Register the bank account cashback should be sent to.
     *
     * The account is resolved with the provider before it is stored. That is one live
     * call per write, spent to make sure a mistyped account number is caught here
     * rather than discovered when ₦300 has already been sent to a stranger — and the
     * name the bank holds is more trustworthy than the one that was typed, so it is
     * what gets saved.
     *
     * Bank codes are provider-specific and are passed to the gateway unchanged, so
     * the code sent here has to match whichever provider is configured.
     */
    public function store(
        Request $request,
        User $user,
        RegisterPayoutAccount $registerAccount,
        PaymentGateway $gateway,
    ): JsonResponse {
        $attributes = $request->validate([
            'bank_code' => ['required', 'string', 'max:10'],
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $resolution = $gateway->resolveAccount($attributes['account_number'], $attributes['bank_code']);

        if ($resolution->failed()) {
            throw ValidationException::withMessages([
                'account_number' => $resolution->failureReason
                    ?? 'This account could not be resolved with the bank.',
            ]);
        }

        // Not every provider returns a name; when one does, it wins.
        $attributes['account_name'] = $resolution->accountName ?? $attributes['account_name'];

        $account = $registerAccount->handle(
            $user,
            $attributes,
            (bool) ($attributes['is_default'] ?? true),
        );

        return (new PayoutAccountResource($account))
            ->response()
            ->setStatusCode($account->wasRecentlyCreated ? 201 : 200);
    }
}
