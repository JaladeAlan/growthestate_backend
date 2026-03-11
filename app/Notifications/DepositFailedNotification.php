<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Deposit;

class DepositFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Deposit $deposit;

    /**
     * Create a new notification instance.
     */
    public function __construct(Deposit $deposit)
    {
        $this->deposit = $deposit;
    }

    /**
     * Notification delivery channels.
     */
    public function via($notifiable)
    {
        return ['database']; // Only database
    }

    /**
     * Database representation.
     */
    public function toDatabase($notifiable)
    {
        $amountKobo = $this->deposit->amount_kobo ?? (int)($this->deposit->amount * 100);

        return [
            'title' => 'Deposit Failed',
            'message' => "Your deposit of ₦" . number_format($amountKobo / 100, 2) . " with reference {$this->deposit->reference} has failed.",
            'reference' => $this->deposit->reference,
            'status' => 'failed',
            'amount_kobo' => $amountKobo,
            'created_at' => now(),
        ];
    }

    /**
     * Fallback array representation.
     */
    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }
}
