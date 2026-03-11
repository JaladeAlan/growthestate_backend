<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use App\Models\Withdrawal;

class WithdrawalConfirmed extends Notification implements ShouldQueue
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

    // Email representation
    public function toMail($notifiable)
    {
        $amount = number_format(($this->withdrawal->amount_kobo ?? 0) / 100, 2);

        return (new MailMessage)
            ->subject('Withdrawal Confirmation')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line("Your withdrawal of ₦{$amount} has been successfully processed.")
            ->line('Transaction Reference: ' . $this->withdrawal->reference)
            ->action('View Account', url('/account'))
            ->line('Thank you for using our application!')
            ->salutation('Best regards, ' . config('app.name'));
    }

    // Database representation
    public function toDatabase($notifiable)
    {
        $amountKobo = $this->withdrawal->amount_kobo ?? 0;

        return [
            'message' => 'Your withdrawal of ₦' . number_format($amountKobo / 100, 2) . ' has been successfully processed.',
            'reference' => $this->withdrawal->reference,
            'amount_kobo' => $amountKobo,
            'status' => 'completed',
            'processed_at' => now(),
        ];
    }

    // Broadcast representation
    public function toBroadcast($notifiable)
    {
        $amountKobo = $this->withdrawal->amount_kobo ?? 0;

        return new BroadcastMessage([
            'message' => 'Your withdrawal of ₦' . number_format($amountKobo / 100, 2) . ' has been successfully processed.',
            'reference' => $this->withdrawal->reference,
            'amount_kobo' => $amountKobo,
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }
}
