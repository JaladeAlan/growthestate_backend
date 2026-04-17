<?php

namespace App\Mail\Transport;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;
use Mailtrap\Config;
use Mailtrap\MailtrapClient;

class MailtrapTransport extends AbstractTransport
{
    public function __construct(protected string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $mailtrap = new MailtrapClient(new Config($this->apiKey));

        $mailtrap->sending()->emails()->send($email);
    }

    public function __toString(): string
    {
        return 'mailtrap';
    }
}