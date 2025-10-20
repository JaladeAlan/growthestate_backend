<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Models\Withdrawal;

class WithdrawalFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $withdrawal;

    public function __construct(Withdrawal $withdrawal)
    {
        $this->withdrawal = $withdrawal;
    }

    public function via($notifiable)
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Withdrawal Failed')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your withdrawal request of ₦' . number_format($this->withdrawal->amount, 2) . ' has failed.')
            ->line('Please try again or contact support.')
            ->action('View Withdrawals', url('/withdrawals'))
            ->line('Thank you for using our service.');
    }

    public function toArray($notifiable)
    {
        return [
            'message' => 'Your withdrawal request of ₦' . number_format($this->withdrawal->amount, 2) . ' has failed.',
            'reference' => $this->withdrawal->reference ?? null,
            'amount' => $this->withdrawal->amount,
            'status' => 'failed',
            'failed_at' => now(),
        ];
    }

    // public function toBroadcast($notifiable)
    // {
    //     return new BroadcastMessage([
    //         'message' => 'Your withdrawal request of ₦' . number_format($this->withdrawal->amount, 2) . ' has failed.',
    //         'reference' => $this->withdrawal->reference ?? null,
    //         'amount' => $this->withdrawal->amount,
    //     ]);
    // }
}
