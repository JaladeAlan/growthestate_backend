<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
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

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Withdrawal Confirmation')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your withdrawal of ₦ ' . number_format($this->withdrawal->amount, 2) . ' has been successfully processed.')
            ->line('Transaction Reference: ' . $this->withdrawal->reference)
            ->action('View Account', url('/account'))
            ->line('Thank you for using our application!')
            ->salutation('Best regards, ' . config('app.name'));
    }

    public function toDatabase($notifiable)
    {
        return [
            'message' => 'Your withdrawal of ₦ ' . number_format($this->withdrawal->amount, 2) . ' has been successfully processed.',
            'reference' => $this->withdrawal->reference,
            'amount' => $this->withdrawal->amount,
            'status' => 'completed',
            'processed_at' => now(),
        ];
    }
    
    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }
    // public function toBroadcast($notifiable)
    // {
    //     return new BroadcastMessage([
    //         'message' => 'Your withdrawal of ₦' . number_format($this->withdrawal->amount, 2) . ' has been successfully processed.',
    //         'reference' => $this->withdrawal->reference,
    //         'amount' => $this->withdrawal->amount,
    //     ]);
    // }
}