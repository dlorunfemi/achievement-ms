<?php

namespace App\Http\Controllers\Dev;

use App\Domain\Achievements\Actions\BuildUserProgression;
use App\Domain\Ordering\Actions\CompleteOrder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\Product;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompletePurchasesController extends Controller
{
    /**
     * Complete one or more purchases for a user, driving the real Ordering entry
     * point so the whole OrderCompleted -> achievements -> badge -> cashback chain
     * runs exactly as it would in production.
     */
    public function __invoke(
        Request $request,
        User $user,
        CompleteOrder $completeOrder,
        BuildUserProgression $buildProgression,
    ): JsonResponse {
        $attributes = $request->validate([
            'count' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
        ]);

        $product = $this->product($attributes['product_id'] ?? null);

        $orders = collect(range(1, $attributes['count'] ?? 1))
            ->map(fn (): Order => $completeOrder->handle(
                Order::factory()->for($user)->for($product)->create()
            ));

        return response()->json([
            'completed_orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->getKey(),
                'amount_minor' => $order->amount_minor,
                'status' => $order->status->value,
                'placed_at' => $order->placed_at?->toIso8601String(),
            ])->all(),
            'product' => [
                'id' => $product->getKey(),
                'name' => $product->name,
            ],
            /*
             * Unlocking is queued, so on the database queue this snapshot is the
             * state before the worker runs. Poll users/{user}/achievements after.
             */
            'queue_connection' => config('queue.default'),
            'progression' => $buildProgression->handle($user)->toArray(),
        ], 201);
    }

    /**
     * Reuse an existing product unless one was named, so repeated calls do not
     * fill the catalog with throwaway rows.
     */
    private function product(?int $productId): Product
    {
        if ($productId !== null) {
            return Product::query()->findOrFail($productId);
        }

        return Product::query()->oldest('id')->first() ?? Product::factory()->create();
    }
}
