<?php

namespace App\Domain\Cashback\Listeners;

use App\Domain\Cashback\Actions\SettleCashback;
use App\Payments\Events\TransferUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Cashback's subscription to the shared Payments module.
 *
 * Queued so a provider's callback is acknowledged immediately: a webhook that waits
 * on a database write is a webhook the provider eventually times out and redelivers.
 */
class SettleCashbackOnTransferUpdated implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct(private SettleCashback $settleCashback)
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(TransferUpdated $event): void
    {
        $this->settleCashback->handle($event->update);
    }
}
