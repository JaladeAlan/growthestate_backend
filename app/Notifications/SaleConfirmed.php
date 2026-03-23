<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class SaleConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 60;

    protected array $transactionData;

    public function __construct($transaction)
    {
        $this->transactionData = [
            'id'            => $transaction->id,
            'units'         => $transaction->units,
            'amount_kobo'   => $transaction->amount_kobo,
            'reference'     => $transaction->reference ?? null,
            'land_title'    => $transaction->land?->title ?? null,
            'date'          => $transaction->transaction_date
                               ? \Carbon\Carbon::parse($transaction->transaction_date)->toFormattedDateString()
                               : now()->toFormattedDateString(),
        ];
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $units       = $this->transactionData['units'];
        $amount      = number_format($this->transactionData['amount_kobo'] / 100, 2);
        $land        = $this->transactionData['land_title'] ?? 'your property';
        $reference   = $this->transactionData['reference'];
        $date        = $this->transactionData['date'];
        $appName     = config('app.name');
        $walletUrl   = rtrim(config('app.frontend_url'), '/') . '/wallet';

        return (new MailMessage)
            ->subject("Sale Confirmed – {$units} unit(s) of {$land}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your sale has been processed successfully. Here is a summary:")
            ->line("**Property:** {$land}")
            ->line("**Units sold:** {$units}")
            ->line("**Amount received:** ₦{$amount}")
            ->line("**Reference:** {$reference}")
            ->line("**Date:** {$date}")
            ->action('View Wallet', $walletUrl)
            ->line("The proceeds have been credited to your main wallet and are available for withdrawal.")
            ->line("Thank you for transacting with {$appName}!")
            ->salutation("Best regards, The {$appName} Team");
    }

    public function toDatabase($notifiable): array
    {
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

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Handle notification delivery failure gracefully.
     * Mail failures must never surface as sale errors.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('SaleConfirmed notification delivery failed', [
            'reference' => $this->transactionData['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}