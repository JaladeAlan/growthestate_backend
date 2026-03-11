<?php

namespace App\Listeners;

use App\Events\LandUnitsPurchased;
use App\Models\User;
use App\Models\Transaction;
use App\Notifications\PurchaseConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPurchaseNotification implements ShouldQueue
{
    public function handle(LandUnitsPurchased $event): void
    {
        $user = User::find($event->userId);
        if (! $user) return;

        // Fetch the most recent transaction for this purchase
        $transaction = Transaction::where('user_id', $event->userId)
            ->where('land_id', $event->landId)
            ->where('type', 'purchase')
            ->where('amount_kobo', $event->totalCost)
            ->latest()
            ->first();

        if (!$transaction) return;

        $user->notify(new PurchaseConfirmed($transaction));
    }
}