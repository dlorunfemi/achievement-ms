<?php

namespace App\Payments\Enums;

enum TransferStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';

    /**
     * Whether the provider has finished with this transfer. A pending transfer is
     * still in flight and must not be re-sent.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
