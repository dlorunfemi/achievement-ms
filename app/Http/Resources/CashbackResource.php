<?php

namespace App\Http\Resources;

use App\Domain\Cashback\Models\Cashback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cashback
 */
class CashbackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'user_id' => $this->user_id,
            'user_badge_id' => $this->user_badge_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'gateway' => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'idempotency_key' => $this->idempotency_key,
            'failure_reason' => $this->failure_reason,
            'attempts' => $this->attempts,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
