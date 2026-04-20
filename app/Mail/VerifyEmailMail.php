<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $verificationCode;

    public function __construct($user, $verificationCode)
    {
        $this->user = $user;
        $this->verificationCode = $verificationCode;
    }

    public function build()
    {
        return $this->subject('Your Email Verification Code')
                    ->view('emails.verify')
                    ->with([
                        'name' => $this->user->name,
                        'verificationCode' => $this->verificationCode,
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