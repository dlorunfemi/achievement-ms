<?php

namespace App\Domain\Ordering\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Only completed orders count toward purchase achievements.
     */
    public function countsAsPurchase(): bool
    {
        return $this === self::Completed;
    }
}
