<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class SaleConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $backoff = 60;

    protected array $transactionData;

    public function __construct($transaction)
    {
        $this->transactionData = [
            'id'          => $transaction->id,
            'units'       => $transaction->units,
            'amount_kobo' => $transaction->amount_kobo,
            'reference'   => $transaction->reference ?? null,
            'land_title'  => $transaction->land?->title ?? null,
            'date'        => $transaction->transaction_date
                ? \Carbon\Carbon::parse($transaction->transaction_date)->toFormattedDateString()
                : now()->toFormattedDateString(),
        ];
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast',
        //  'mail'
         ];
    }

    public function toDatabase($notifiable): array
    {
        $key = 'notif:sale:db:' . $this->transactionData['reference'];

        $lock = Cache::lock($key, 120);

        if (! $lock->get()) {
            Log::info('SaleConfirmed duplicate DB notification prevented', [
                'reference' => $this->transactionData['reference'],
            ]);
            return [];
        }

        try {
            return [
                'transaction_id' => $this->transactionData['id'],
                'units'          => $this->transactionData['units'],
                'amount_kobo'    => $this->transactionData['amount_kobo'],
                'land_title'     => $this->transactionData['land_title'],
                'reference'      => $this->transactionData['reference'],
                'message'        => "Your sale of {$this->transactionData['units']} unit(s) of "
                    . ($this->transactionData['land_title'] ?? 'property')
                    . " has been confirmed.",
            ];
        } finally {
            $lock->release();
        }
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'transaction_id' => $this->transactionData['id'],
            'units'          => $this->transactionData['units'],
            'amount_kobo'    => $this->transactionData['amount_kobo'],
            'land_title'     => $this->transactionData['land_title'],
            'reference'      => $this->transactionData['reference'],
            'message'        => "Your sale of {$this->transactionData['units']} unit(s) has been confirmed!",
            'timestamp'      => now(),
        ]);
    }

    public function toMail($notifiable): MailMessage
    {
        $data = (object) array_merge($this->transactionData, [
            'user' => $notifiable,
            'land' => isset($this->transactionData['land_title'])
                ? (object) ['title' => $this->transactionData['land_title']]
                : null,
        ]);

        return (new MailMessage)
            ->subject("Sale Confirmed – {$this->transactionData['units']} unit(s) of " . ($this->transactionData['land_title'] ?? 'your property'))
            ->view('emails.sale_confirmed', ['logoUrl' => asset('images/reu-logo.png'), 'transaction' => $data]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SaleConfirmed notification failed', [
            'reference' => $this->transactionData['reference'],
            'error'     => $exception->getMessage(),
        ]);
    }  
}