<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use App\Models\Withdrawal;

class WithdrawalFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
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
        $amount    = number_format($this->withdrawalData['amount_kobo'] / 100, 2);
        $ref       = $this->withdrawalData['reference'];
        $appName   = config('app.name');
        $walletUrl = rtrim(config('app.frontend_url'), '/') . '/wallet';

        return (new MailMessage)
            ->subject("Withdrawal Failed – ₦{$amount}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Unfortunately your withdrawal could not be processed.")
            ->line("**Amount:** ₦{$amount}")
            ->line("**Reference:** {$ref}")
            ->line("The funds have been returned to your wallet.")
            ->action('View Wallet', $walletUrl)
            ->line("Please check your bank details and try again. If the problem persists, contact support.")
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
                               . " failed. The funds have been returned to your wallet.",
        ];
    }

    /**
     * Handle notification delivery failure gracefully.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('WithdrawalFailedNotification delivery failed', [
            'reference' => $this->withdrawalData['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}