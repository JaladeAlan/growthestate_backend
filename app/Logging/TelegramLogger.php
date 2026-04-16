<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Illuminate\Support\Facades\Http;

class TelegramLogger extends AbstractProcessingHandler
{
    public function __construct()
    {
        parent::__construct(Level::Error); // only error and above
    }

    protected function write(LogRecord $record): void
    {
        $token  = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (!$token || !$chatId) {
            return;
        }

        $env     = config('app.env');
        $level   = strtoupper($record->level->name);
        $message = $record->message;
        $context = $record->context ? json_encode($record->context, JSON_PRETTY_PRINT) : '';

        $text = "*[{$env}] {$level}*\n{$message}";

        if ($context && $context !== '[]') {
            $text .= "\n```{$context}```";
        }

        Http::timeout(3)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => mb_substr($text, 0, 4096),
            'parse_mode' => 'Markdown',
        ]);
    }
}