<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $backoff = 60;

    protected array $withdrawalData;

    public function __construct($withdrawal)
    {
        $this->withdrawalData = [
            'id'             => $withdrawal->id,
            'amount_kobo'    => $withdrawal->amount_kobo,
            'reference'      => $withdrawal->reference ?? null,
            'bank_name'      => $withdrawal->bank_name ?? null,
            'account_number' => $withdrawal->account_number ?? null,
            'date'           => $withdrawal->withdrawal_date
                ? \Carbon\Carbon::parse($withdrawal->withdrawal_date)->toFormattedDateString()
                : now()->toFormattedDateString(),
        ];
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toDatabase($notifiable): array
    {
        $key = 'notif:withdrawal:db:' . $this->withdrawalData['reference'];

        $lock = Cache::lock($key, 120);

        if (! $lock->get()) {
            Log::info('WithdrawalConfirmed duplicate prevented', [
                'reference' => $this->withdrawalData['reference'],
            ]);
            return [];
        }

        try {
            return [
                'withdrawal_id'  => $this->withdrawalData['id'],
                'amount_kobo'    => $this->withdrawalData['amount_kobo'],
                'bank_name'      => $this->withdrawalData['bank_name'],
                'account_number' => $this->withdrawalData['account_number'],
                'reference'      => $this->withdrawalData['reference'],
                'message'        => 'Your withdrawal of ₦'
                    . number_format($this->withdrawalData['amount_kobo'] / 100, 2)
                    . ' has been confirmed.',
            ];
        } finally {
            $lock->release();
        }
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'withdrawal_id' => $this->withdrawalData['id'],
            'amount_kobo'   => $this->withdrawalData['amount_kobo'],
            'bank_name'     => $this->withdrawalData['bank_name'],
            'reference'     => $this->withdrawalData['reference'],
            'message'       => '₦' . number_format($this->withdrawalData['amount_kobo'] / 100, 2) . ' withdrawal confirmed!',
            'timestamp'     => now(),
        ]);
    }

    public function toMail($notifiable): MailMessage
    {
        $data = (object) array_merge($this->withdrawalData, [
            'user' => $notifiable,
        ]);

        return (new MailMessage)
            ->subject('Withdrawal Confirmed – ₦' . number_format($this->withdrawalData['amount_kobo'] / 100, 2))
            ->view('emails.withdrawal_confirmed', ['withdrawal' => $data]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('WithdrawalConfirmed notification failed', [
            'reference' => $this->withdrawalData['reference'],
            'error'     => $exception->getMessage(),
        ]);
    }
}