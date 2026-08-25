<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderCompleted;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * The single place an order becomes a completed purchase.
 *
 * Publishing OrderCompleted here rather than from a model observer keeps the event a
 * deliberate domain statement — "this purchase counts" — instead of a side effect of
 * any write that happens to touch the status column.
 */
final class CompleteOrder
{
    public function handle(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            // Re-read under a row lock so two concurrent completions cannot both
            // observe a pending order and publish the event twice.
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Completed) {
                return $locked;
            }

            $locked->forceFill([
                'status' => OrderStatus::Completed,
                'placed_at' => $locked->placed_at ?? now(),
            ])->save();

            OrderCompleted::dispatch($locked);

            return $locked;
        });
    }
}
