<?php

namespace App\Http\Resources;

use App\Domain\Cashback\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayoutAccount
 */
class PayoutAccountResource extends JsonResource
{
    /**
     * Deliberately omits recipient_tokens: those are provider credentials for this
     * account, and nothing outside the payments module has any use for them.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'user_id' => $this->user_id,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'account_name' => $this->account_name,
            'currency' => $this->currency,
            'is_default' => $this->is_default,
        ];
    }
}
