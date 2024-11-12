<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class WithdrawalConfirmed extends Notification
{
    use Queueable;

    protected $amount;

    public function __construct($amount)
    {
        $this->amount = $amount;
    }

    public function via($notifiable)
    {
        return ['mail']; // You can add other channels like 'database', 'broadcast', etc.
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('Withdrawal Confirmation')
                    ->line('Your withdrawal of ' . $this->amount . ' has been successfully processed.')
                    ->action('View Account', url('/account'))
                    ->line('Thank you for using our application!');
    }
}
