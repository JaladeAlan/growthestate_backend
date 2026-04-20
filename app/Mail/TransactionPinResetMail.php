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
                    ->view('emails.pin_reset')
                    ->with([
                        'userName' => $this->user->name,
                        'code' => $this->code,
                       'logoUrl'          => $this->embedLogo(),
                    ]);
    }

    private function embedLogo(): string
        {
            // Embeds the image directly into the email as base64
            $path = public_path('images/reu-logo.png');
            $data = base64_encode(file_get_contents($path));
            return 'data:image/png;base64,' . $data;
        }
}
