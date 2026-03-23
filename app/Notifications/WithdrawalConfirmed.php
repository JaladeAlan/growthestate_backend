<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use App\Models\Withdrawal;

class WithdrawalConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 60;

    protected array $withdrawalData;

    public function __construct(Withdrawal $withdrawal)
    {
        $this->withdrawalData = [
            'id'          => $withdrawal->id,
            'reference'   => $withdrawal->reference,
            'amount_kobo' => $withdrawal->amount_kobo,
        ];
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $amount   = number_format($this->withdrawalData['amount_kobo'] / 100, 2);
        $ref      = $this->withdrawalData['reference'];
        $appName  = config('app.name');
        $walletUrl = rtrim(config('app.frontend_url'), '/') . '/wallet';

        return (new MailMessage)
            ->subject("Withdrawal Confirmed – ₦{$amount}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your withdrawal has been processed successfully.")
            ->line("**Amount:** ₦{$amount}")
            ->line("**Reference:** {$ref}")
            ->action('View Wallet', $walletUrl)
            ->line("Funds should arrive in your bank account within 1–3 business days.")
            ->line("Thank you for using {$appName}!")
            ->salutation("Best regards, The {$appName} Team");
    }

    public function toDatabase($notifiable): array
    {
        return [
            'withdrawal_id' => $this->withdrawalData['id'],
            'amount_kobo'   => $this->withdrawalData['amount_kobo'],
            'reference'     => $this->withdrawalData['reference'],
            'message'       => "Your withdrawal of ₦"
                               . number_format($this->withdrawalData['amount_kobo'] / 100, 2)
                               . " has been confirmed.",
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
        Log::warning('WithdrawalConfirmed notification delivery failed', [
            'reference' => $this->withdrawalData['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}