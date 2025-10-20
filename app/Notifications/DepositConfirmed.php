<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

class DepositConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    protected $amount;

    public function __construct($amount)
    {
        $this->amount = $amount;
    }

    public function via($notifiable)
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Deposit Confirmation')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your deposit of ₦ ' . number_format($this->amount, 2) . ' was successfully processed.')
            ->action('View Account', url('/account'))
            ->line('Thank you for using our application!')
            ->salutation('Best regards, ' . config('app.name'));
    }

    public function toDatabase($notifiable)
    {
        return [
            'message' => 'Your deposit of ₦ ' . number_format($this->amount, 2) . ' was successful.',
            'amount' => $this->amount,
            'type' => 'deposit',
            'timestamp' => now(),
        ];
    }

    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }

    // public function toBroadcast($notifiable)
    // {
    //     return new BroadcastMessage([
    //         'message' => 'Your deposit of ₦' . number_format($this->amount, 2) . ' was successful.',
    //         'amount' => $this->amount,
    //         'type' => 'deposit',
    //     ]);
    // }
}
