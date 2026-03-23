<?php

namespace App\Listeners;

use App\Events\LandUnitsSold;
use App\Models\User;
use App\Models\Transaction;
use App\Notifications\SaleConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendSaleNotification implements ShouldQueue
{
    public int $tries   = 1;
    public int $backoff = 60;

    public function handle(LandUnitsSold $event): void
    {
        $lock = Cache::lock('notif:sale:' . $event->reference, 120);

        if (! $lock->get()) {
            Log::info('SendSaleNotification: duplicate suppressed', [
                'reference' => $event->reference,
            ]);
            return;
        }

        try {
            $user = User::find($event->userId);
            if (! $user) return;

            $transaction = Transaction::where('reference', $event->reference)->firstOrFail();
            if (! $transaction) {
                Log::warning('SendSaleNotification: transaction not found', [
                    'reference' => $event->reference,
                    'user_id'   => $event->userId,
                ]);
                return;
            }

            $user->notify(new SaleConfirmed($transaction));

        } finally {
            $lock->release();
        }
    }

    public function failed(LandUnitsSold $event, \Throwable $exception): void
    {
        Log::error('SendSaleNotification: failed', [
            'reference' => $event->reference,
            'user_id'   => $event->userId,
            'error'     => $exception->getMessage(),
        ]);
    }
}