<?php

namespace App\Domain\Cashback\Events;

use App\Domain\Cashback\Models\Cashback;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The reward for a badge has settled with the payment provider.
 */
class CashbackPaid
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
