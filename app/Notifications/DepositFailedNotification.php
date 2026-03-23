<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use App\Models\Deposit;

class DepositFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $backoff = 60;

    protected array $depositData;

    public function __construct(Deposit $deposit)
    {
        $this->depositData = [
            'id'          => $deposit->id,
            'reference'   => $deposit->reference,
            'amount_kobo' => $deposit->amount_kobo ?? (int) ($deposit->amount * 100),
        ];
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $amount    = number_format($this->depositData['amount_kobo'] / 100, 2);
        $ref       = $this->depositData['reference'];
        $appName   = config('app.name');
        $walletUrl = rtrim(config('app.frontend_url'), '/') . '/wallet';

        return (new MailMessage)
            ->subject("Deposit Failed – ₦{$amount}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your deposit of ₦{$amount} could not be processed.")
            ->line("**Reference:** {$ref}")
            ->line("No funds have been deducted from your account.")
            ->action('Try Again', $walletUrl)
            ->line("If you believe this is an error, please contact support with your reference number.")
            ->salutation("Best regards, The {$appName} Team");
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'       => 'Deposit Failed',
            'message'     => "Your deposit of ₦"
                             . number_format($this->depositData['amount_kobo'] / 100, 2)
                             . " with reference {$this->depositData['reference']} has failed.",
            'reference'   => $this->depositData['reference'],
            'status'      => 'failed',
            'amount_kobo' => $this->depositData['amount_kobo'],
        ];
    }

    /**
     * Handle notification delivery failure gracefully.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('DepositFailedNotification delivery failed', [
            'reference' => $this->depositData['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}