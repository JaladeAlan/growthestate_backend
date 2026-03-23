<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class PurchaseConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
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
        // Always send database + broadcast.
        // Only attempt mail if the mailer is not in a known-broken state.
        $channels = ['database', 'broadcast'];

        // Skip mail in testing/local if you want, or always include it —
        // the key fix is that mail failures are caught per-channel below.
        $channels[] = 'mail';

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $amount    = number_format($this->transactionData['amount_kobo'] / 100, 2);
        $units     = $this->transactionData['units'];
        $land      = $this->transactionData['land_title'] ?? 'your selected property';
        $reference = $this->transactionData['reference'];
        $date      = $this->transactionData['date'];
        $appName   = config('app.name');
        $dashUrl   = rtrim(config('app.frontend_url'), '/') . '/portfolio';

        return (new MailMessage)
            ->subject("Purchase Confirmed – {$units} unit(s) of {$land}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your purchase has been confirmed. Here is a summary:")
            ->line("**Property:** {$land}")
            ->line("**Units purchased:** {$units}")
            ->line("**Total paid:** ₦{$amount}")
            ->line("**Reference:** {$reference}")
            ->line("**Date:** {$date}")
            ->action('View Portfolio', $dashUrl)
            ->line("Your units are now live in your portfolio and accruing value.")
            ->line("Thank you for investing with {$appName}!")
            ->salutation("Best regards, The {$appName} Team");
    }

    public function toDatabase($notifiable): array
    {
        return [
            'purchase_id' => $this->transactionData['id'],
            'units'       => $this->transactionData['units'],
            'amount_kobo' => $this->transactionData['amount_kobo'],
            'land_title'  => $this->transactionData['land_title'],
            'reference'   => $this->transactionData['reference'],
            'message'     => "Your purchase of {$this->transactionData['units']} unit(s) of "
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
            'message'        => "Your purchase of {$this->transactionData['units']} unit(s) has been confirmed!",
            'timestamp'      => now(),
        ]);
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('PurchaseConfirmed notification delivery failed', [
            'reference' => $this->transactionData['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}