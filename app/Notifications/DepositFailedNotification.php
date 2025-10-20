<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Deposit;

class DepositFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $deposit;

    /**
     * Create a new notification instance.
     */
    public function __construct(Deposit $deposit)
    {
        $this->deposit = $deposit;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable)
    {
        return ['database']; // Only stores in the database
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase($notifiable)
    {
        return [
            'title' => 'Deposit Failed',
            'message' => "Your deposit of ₦" . number_format($this->deposit->amount, 2) . " with reference {$this->deposit->reference} has failed.",
            'reference' => $this->deposit->reference,
            'status' => 'failed',
            'amount' => $this->deposit->amount,
            'created_at' => now(),
        ];
    }
}
