<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

class DepositConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    protected $amountKobo;

    public function __construct(int $amountKobo)
    {
        $this->amountKobo = $amountKobo;
    }

    /**
     * Delivery channels
     */
    public function via($notifiable)
    {
        return ['mail', 'database']; 
    }

    /**
     * Email representation
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Deposit Confirmation')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your deposit of ₦' . number_format($this->amountKobo / 100, 2) . ' was successfully processed.')
            ->action('View Account', url('/account'))
            ->line('Thank you for using our application!')
            ->salutation('Best regards, ' . config('app.name'));
    }

    /**
     * Database representation
     */
    public function toDatabase($notifiable)
    {
        return [
            'message' => 'Your deposit of ₦' . number_format($this->amountKobo / 100, 2) . ' was successful.',
            'amount_kobo' => $this->amountKobo,
            'type' => 'deposit',
            'created_at' => now(),
        ];
    }

    /**
     * Array fallback
     */
    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }
}
