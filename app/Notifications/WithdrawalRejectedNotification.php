<?php

namespace App\Notifications;

use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Withdrawal $withdrawal) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format($this->withdrawal->amount_kobo / 100, 2);

        return (new MailMessage)
            ->subject('Withdrawal Request Rejected — Funds Returned')
            ->greeting("Hi {$notifiable->name},")
            ->line("Your withdrawal request of ₦{$amount} (Ref: {$this->withdrawal->reference}) has been rejected and the funds have been returned to your wallet.")
            ->when(
                $this->withdrawal->rejection_reason,
                fn ($mail) => $mail->line("Reason: {$this->withdrawal->rejection_reason}")
            )
            ->line('If you believe this is an error, please contact our support team.')
            ->action('Contact Support', config('app.frontend_url') . '/support');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'             => 'withdrawal_rejected',
            'withdrawal_id'    => $this->withdrawal->id,
            'reference'        => $this->withdrawal->reference,
            'amount_kobo'      => $this->withdrawal->amount_kobo,
            'rejection_reason' => $this->withdrawal->rejection_reason,
        ];
    }
}
