<?php

namespace App\Listeners;

use App\Events\LandUnitsSold;
use App\Models\User;
use App\Models\Transaction;
use App\Notifications\SaleConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSaleNotification implements ShouldQueue
{
    public function handle(LandUnitsSold $event): void
    {
        $user = User::find($event->userId);
        if (! $user) return;

        // Fetch the most recent sale transaction
        $transaction = Transaction::where('user_id', $event->userId)
            ->where('land_id', $event->landId)
            ->where('type', 'sale')
            ->where('amount_kobo', $event->totalReceived)
            ->latest()
            ->first();

        if (!$transaction) return;

        $user->notify(new SaleConfirmed($transaction));
    }
}