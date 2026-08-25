<?php

namespace App\Console\Commands;

use App\Domain\Cashback\Actions\ReconcilePendingCashbacks;
use Illuminate\Console\Command;

class ReconcileCashbacks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cashbacks:reconcile
                            {--minutes= : Only sweep payouts untouched for this many minutes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ask the payment provider what became of payouts still marked processing';

    /**
     * Execute the console command.
     */
    public function handle(ReconcilePendingCashbacks $reconcile): int
    {
        $minutes = $this->option('minutes');

        $settled = $reconcile->handle($minutes === null ? null : (int) $minutes);

        $this->info($settled === 0
            ? 'No pending payouts had settled.'
            : "Settled {$settled} pending payout(s).");

        return self::SUCCESS;
    }
}
