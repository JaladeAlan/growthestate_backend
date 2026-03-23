<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class DepositConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $backoff = 60;

    protected int $amountKobo;
    protected string $reference;
    protected string $date;

    public function __construct(int $amountKobo, string $reference = '')
    {
        $this->amountKobo = $amountKobo;
        $this->reference  = $reference;
        $this->date       = now()->toFormattedDateString();
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase($notifiable): array
    {
        $key = 'notif:deposit:db:' . $this->reference;

        $lock = Cache::lock($key, 120);

        if (! $lock->get()) {
            Log::info('DepositConfirmed duplicate prevented', [
                'reference' => $this->reference,
            ]);
            return [];
        }

        try {
            return [
                'message'     => 'Your deposit of ₦' . number_format($this->amountKobo / 100, 2) . ' was successful.',
                'amount_kobo' => $this->amountKobo,
                'reference'   => $this->reference,
                'type'        => 'deposit',
            ];
        } finally {
            $lock->release();
        }
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deposit Confirmed – ₦' . number_format($this->amountKobo / 100, 2))
            ->view('emails.deposit_confirmed', [
                'notifiable' => $notifiable,
                'amountKobo' => $this->amountKobo,
                'reference'  => $this->reference,
                'date'       => $this->date,
            ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('DepositConfirmed notification failed', [
            'reference' => $this->reference,
            'error'     => $exception->getMessage(),
        ]);
    }
}