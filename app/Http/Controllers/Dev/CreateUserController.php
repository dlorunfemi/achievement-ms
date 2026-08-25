<?php

namespace App\Http\Controllers\Dev;

use App\Domain\Cashback\Models\PayoutAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CreateUserController extends Controller
{
    /**
     * Create a customer with a bank account on file.
     *
     * A payout account is the precondition for cashback, so the two are created
     * together — a user without one unlocks badges but is never paid, which reads
     * like a bug when you are exercising the flow by hand.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email'],
            'bank_code' => ['sometimes', 'string', 'max:10'],
            'bank_name' => ['sometimes', 'string', 'max:255'],
            'account_number' => ['sometimes', 'string', 'max:20'],
            'account_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user = User::factory()->create(Arr::only($attributes, ['name', 'email']));

        $account = PayoutAccount::factory()->default()->for($user)->create(
            Arr::only($attributes, ['bank_code', 'bank_name', 'account_number', 'account_name'])
        );

        return response()->json([
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'payout_account' => [
                'bank_code' => $account->bank_code,
                'bank_name' => $account->bank_name,
                'account_number' => $account->account_number,
                'account_name' => $account->account_name,
            ],
            'links' => [
                'achievements' => route('users.achievements', $user),
                'purchases' => route('dev.users.purchases.store', $user),
                'cashbacks' => route('dev.users.cashbacks', $user),
            ],
        ], 201);
    }
}
