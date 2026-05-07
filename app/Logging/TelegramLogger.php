<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramLogger extends AbstractProcessingHandler
{
    public function __construct()
    {
        parent::__construct(Level::Warning); // catches warning, error, critical
    }

    public function __invoke(array $config): \Monolog\Logger
    {
        $logger = new \Monolog\Logger('telegram');
        $logger->pushHandler(new self());
        return $logger;
    }
    
    protected function write(LogRecord $record): void
    {
        $token  = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (!$token || !$chatId) {
            Log::channel('daily')->warning('TelegramLogger: bot_token or chat_id not configured.');
            return;
        }

        $env     = strtoupper(config('app.env'));
        $level   = strtoupper($record->level->name);
        $message = htmlspecialchars($record->message, ENT_QUOTES);
        $context = $record->context
            ? json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        $text = "<b>[{$env}] {$level}</b>\n{$message}";

        if ($context && $context !== '[]') {
            // Truncate context separately so the message header always survives
            $contextSnippet = mb_substr($context, 0, 3000);
            $text .= "\n<pre>" . htmlspecialchars($contextSnippet, ENT_QUOTES) . "</pre>";
        }

        // Telegram hard limit is 4096 chars
        $text = mb_substr($text, 0, 4096);

        try {
            $response = Http::timeout(3)->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                [
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ]
            );

            if ($response->failed()) {
                Log::channel('daily')->error('TelegramLogger: delivery failed', [
                    'http_status' => $response->status(),
                    'body'        => $response->body(),
                    'original'    => $record->message,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let a logging failure bubble up and break the application
            Log::channel('daily')->error('TelegramLogger: exception during delivery', [
                'error'    => $e->getMessage(),
                'original' => $record->message,
            ]);
        }
    }
}