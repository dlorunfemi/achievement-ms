<?php

namespace App\Payments\Events;

use App\Payments\ValueObjects\TransferUpdate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payment provider has told us something new about a transfer we sent.
 *
 * Published by the shared Payments module and consumed by whichever feature owns the
 * payout. Payments deliberately does not know that Cashback exists, so this event is
 * the whole of the coupling between them.
 */
class TransferUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $gateway,
        public TransferUpdate $update,
    ) {
        //
    }
}
