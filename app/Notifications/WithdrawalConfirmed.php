<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class WithdrawalConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
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

    public function toMail($notifiable): MailMessage
    {
        $data = (object) array_merge($this->withdrawalData, [
            'user' => $notifiable,
        ]);

        return (new MailMessage)
            ->subject('Withdrawal Confirmed – ₦' . number_format($this->withdrawalData['amount_kobo'] / 100, 2))
            ->view('emails.withdrawal_confirmed', ['withdrawal' => $data]);
    }

    public function toDatabase($notifiable): array
    {
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
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'withdrawal_id'  => $this->withdrawalData['id'],
            'amount_kobo'    => $this->withdrawalData['amount_kobo'],
            'bank_name'      => $this->withdrawalData['bank_name'],
            'reference'      => $this->withdrawalData['reference'],
            'message'        => '₦' . number_format($this->withdrawalData['amount_kobo'] / 100, 2) . ' withdrawal confirmed!',
            'timestamp'      => now(),
        ]);
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('WithdrawalConfirmed notification delivery failed', [
            'reference' => $this->withdrawalData['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}