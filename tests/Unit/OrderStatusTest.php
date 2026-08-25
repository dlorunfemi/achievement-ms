<?php

use App\Domain\Ordering\Enums\OrderStatus;

it('counts only completed orders as purchases', function () {
    expect(OrderStatus::Completed->countsAsPurchase())->toBeTrue()
        ->and(OrderStatus::Pending->countsAsPurchase())->toBeFalse()
        ->and(OrderStatus::Cancelled->countsAsPurchase())->toBeFalse();
});

it('is backed by stable string values', function () {
    expect(array_column(OrderStatus::cases(), 'value'))
        ->toBe(['pending', 'completed', 'cancelled']);
});
