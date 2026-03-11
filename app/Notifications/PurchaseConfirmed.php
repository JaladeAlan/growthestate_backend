<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class PurchaseConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    protected $transactionData;

    public function __construct($transaction)
    {
        // Store only essential data for queue serialization
        $this->transactionData = [
            'id' => $transaction->id,
            'units' => $transaction->units,
            'amount_kobo' => $transaction->amount_kobo,
        ];
    }

    /**
     * Delivery channels
     */
    public function via($notifiable)
    {
        return ['mail', 'database', 'broadcast'];
    }

    /**
     * Database representation
     */
    public function toDatabase($notifiable)
    {
        return [
            'purchase_id' => $this->transactionData['id'],
            'units' => $this->transactionData['units'],
            'amount_kobo' => $this->transactionData['amount_kobo'],
            'message' => 'Your purchase of ' . $this->transactionData['units'] . ' units has been confirmed!',
            'created_at' => now(),
        ];
    }

    /**
     * Email representation
     */
    public function toMail($notifiable)
    {
        $amount = number_format($this->transactionData['amount_kobo'] / 100, 2);

        return (new MailMessage)
            ->subject('Purchase Confirmation')
            ->line("Your purchase of {$this->transactionData['units']} units has been confirmed!")
            ->line("Total amount paid: ₦{$amount}")
            ->action('View transaction', url('/transactions/' . $this->transactionData['id']))
            ->line('Thank you for your transaction!');
    }

    /**
     * Broadcast representation
     */
    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'transaction_id' => $this->transactionData['id'],
            'units' => $this->transactionData['units'],
            'amount_kobo' => $this->transactionData['amount_kobo'],
            'message' => 'Your transaction has been confirmed!',
            'timestamp' => now(),
        ]);
    }

    /**
     * Fallback array
     */
    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }
}
