<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TransactionPinResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $code;

    /**
     * Create a new message instance.
     */
    public function __construct($user, $code)
    {
        $this->user = $user;
        $this->code = $code;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Transaction PIN Reset Code')
                    ->markdown('emails.pin_reset')
                    ->with([
                        'userName' => $this->user->name,
                        'code' => $this->code,
                    ]);
    }
}
