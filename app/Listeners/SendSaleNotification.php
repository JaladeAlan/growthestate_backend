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
    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 60, 120];
    }

    public function handle(LandUnitsSold $event): void
    {
        $key = 'event:sale:' . $event->reference;

        if (! Cache::add($key, true, 300)) {
            Log::info('SendSaleNotification: duplicate event skipped', [
                'reference' => $event->reference,
            ]);
            return;
        }

        try {
            $user = User::find($event->userId);

            if (! $user) {
                Log::warning('SendSaleNotification: user not found', [
                    'user_id' => $event->userId,
                ]);
                return;
            }

            $transaction = Transaction::where('reference', $event->reference)->first();

            if (! $transaction) {
                throw new \Exception('Transaction not found yet (possible race condition)');
            }

            $user->notify(new SaleConfirmed($transaction));

        } catch (\Throwable $e) {
            Cache::forget($key);

            Log::error('SendSaleNotification failed', [
                'reference' => $event->reference,
                'user_id'   => $event->userId,
                'error'     => $e->getMessage(),
            ]);

            throw $e; // allow retry
        }
    }

    public function failed(LandUnitsSold $event, \Throwable $exception): void
    {
        Log::error('SendSaleNotification: job failed permanently', [
            'reference' => $event->reference,
            'user_id'   => $event->userId,
            'error'     => $exception->getMessage(),
        ]);
    }
}