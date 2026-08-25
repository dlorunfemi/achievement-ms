<?php

namespace App\Domain\Cashback\Events;

use App\Domain\Cashback\Models\Cashback;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A transfer attempt was rejected by the payment provider. The cashback row keeps the
 * failure reason and remains retryable.
 */
class CashbackFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Cashback $cashback)
    {
        //
    }
}
