<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

class DepositConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 60;

    protected int $amountKobo;
    protected string $reference;

    public function __construct(int $amountKobo, string $reference = '')
    {
        $this->amountKobo = $amountKobo;
        $this->reference  = $reference;
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $amount  = number_format($this->amountKobo / 100, 2);
        $appName = config('app.name');
        $dashUrl = rtrim(config('app.frontend_url'), '/') . '/wallet';

        return (new MailMessage)
            ->subject("Deposit Confirmed – ₦{$amount}")
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line("Your deposit of ₦{$amount} was successfully processed.")
            ->when($this->reference, fn ($m) => $m->line("**Reference:** {$this->reference}"))
            ->action('View Wallet', $dashUrl)
            ->line("Thank you for using {$appName}!")
            ->salutation("Best regards, The {$appName} Team");
    }

    public function toDatabase($notifiable): array
    {
        return [
            'message'     => 'Your deposit of ₦' . number_format($this->amountKobo / 100, 2) . ' was successful.',
            'amount_kobo' => $this->amountKobo,
            'reference'   => $this->reference,
            'type'        => 'deposit',
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Handle notification delivery failure gracefully.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('DepositConfirmed notification delivery failed', [
            'reference' => $this->reference,
            'error'     => $exception->getMessage(),
        ]);
    }
}