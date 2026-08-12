<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

class AccountUnderReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $backoff = 60;

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'message' => 'Your account is under review. Some actions may be temporarily limited.',
            'type'    => 'account_under_review',
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Account Is Under Review')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your account is currently under review as part of our standard compliance checks.')
            ->line('Some actions may be temporarily limited while this review is in progress.');
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('AccountUnderReviewNotification failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
