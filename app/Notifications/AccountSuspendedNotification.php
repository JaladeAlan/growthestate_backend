<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

class AccountSuspendedNotification extends Notification implements ShouldQueue
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
            'message' => 'Your account has been suspended pending compliance review. Contact support for details.',
            'type'    => 'account_suspended',
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Account Has Been Suspended')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your account has been suspended following a compliance review.')
            ->line('If you believe this is a mistake, please contact our support team.');
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('AccountSuspendedNotification failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
