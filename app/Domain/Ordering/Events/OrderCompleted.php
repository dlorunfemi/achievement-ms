<?php

namespace App\Domain\Ordering\Events;

use App\Domain\Ordering\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when an order reaches the completed state. This is the only entry point the
 * Achievements context listens to; it knows nothing about how orders are created.
 *
 * Dispatched after commit so a listener can never read an order that was rolled back.
 */
class OrderCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Order $order)
    {
        //
    }
}
