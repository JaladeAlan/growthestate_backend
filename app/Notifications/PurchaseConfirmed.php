<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    protected $purchase;

    public function __construct(Model $purchase)
    {
        $this->purchase = $purchase;
    }

    // Delivery channels
    public function via($notifiable)
    {
        return ['database']; // You can also use ['mail'] or others
    }

    // Database representation
    public function toDatabase($notifiable)
    {
        return [
            'purchase_id' => $this->purchase->id,
            'units' => $this->purchase->units,
            'total_amount_paid' => $this->purchase->total_amount_paid,
            'message' => 'Your purchase has been confirmed!',
            'created_at' => now(),
        ];
    }

    // Optional: Email representation
    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('Purchase Confirmation')
                    ->line('Your purchase of ' . $this->purchase->units . ' units has been confirmed!')
                    ->action('View Purchase', url('/purchases/' . $this->purchase->id))
                    ->line('Thank you for your purchase!');
    }
}
