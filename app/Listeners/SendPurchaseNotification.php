<?php

namespace App\Listeners;

use App\Events\LandUnitsPurchased;
use App\Models\User;
use App\Models\Transaction;
use App\Notifications\PurchaseConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendPurchaseNotification implements ShouldQueue
{
    public int $tries   = 3;
    public int $backoff = 60;

    public function handle(LandUnitsPurchased $event): void
    {
        $user = User::find($event->userId);
        if (! $user) return;

        $transaction = Transaction::where('reference', $event->reference)->first();
        if (! $transaction) {
            Log::warning('SendPurchaseNotification: transaction not found', [
                'reference' => $event->reference,
                'user_id'   => $event->userId,
            ]);
            return;
        }

        $user->notify(new PurchaseConfirmed($transaction));
    }


    public function failed(LandUnitsPurchased $event, \Throwable $exception): void
    {
        Log::error('SendPurchaseNotification: notification delivery failed', [
            'reference' => $event->reference,
            'user_id'   => $event->userId,
            'error'     => $exception->getMessage(),
        ]);
    }
}