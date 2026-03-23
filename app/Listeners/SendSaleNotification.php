<?php

namespace App\Listeners;

use App\Events\LandUnitsSold;
use App\Models\User;
use App\Models\Transaction;
use App\Notifications\SaleConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendSaleNotification implements ShouldQueue
{
    public int $tries   = 3;
    public int $backoff = 60;

    public function handle(LandUnitsSold $event): void
    {
        $user = User::find($event->userId);
        if (! $user) return;

        $transaction = Transaction::where('reference', $event->reference)->first();
        if (! $transaction) {
            Log::warning('SendSaleNotification: transaction not found', [
                'reference' => $event->reference,
                'user_id'   => $event->userId,
            ]);
            return;
        }

        $user->notify(new SaleConfirmed($transaction));
    }

    /**
     * Handle a job failure — log it but do NOT re-throw.
     * Mail failures should never surface as sale failures.
     */
    public function failed(LandUnitsSold $event, \Throwable $exception): void
    {
        Log::error('SendSaleNotification: notification delivery failed', [
            'reference' => $event->reference,
            'user_id'   => $event->userId,
            'error'     => $exception->getMessage(),
        ]);
    }
}