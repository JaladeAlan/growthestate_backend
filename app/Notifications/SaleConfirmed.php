<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SaleConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    protected $transaction;

    public function __construct($transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * Delivery channels
     */
    public function via($notifiable)
    {
        // Use database and mail; broadcast optional
        return ['database', 'mail'];
    }

    /**
     * Database representation
     */
    public function toDatabase($notifiable)
    {
        return [
            'transaction_id' => $this->transaction->id,
            'units' => $this->transaction->units,
            'amount_kobo' => $this->transaction->amount_kobo,
            'message' => 'Your sale of ' . $this->transaction->units . ' units has been confirmed!',
            'created_at' => now(),
        ];
    }

    /**
     * Email representation
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Sale Confirmation')
            ->line('Your sale of ' . $this->transaction->units . ' units has been confirmed!')
            ->line('Amount received: ₦' . number_format($this->transaction->amount_kobo / 100, 2))
            ->action('View Sale', url('/transactions/' . $this->transaction->id))
            ->line('Thank you for transacting with us!');
    }

    /**
     * Fallback array representation
     */
    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }
}
