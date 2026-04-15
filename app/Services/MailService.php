<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class MailService
{
    // Thresholds before switching
    private const RESEND_LIMIT    = 95;
    private const MAILERSEND_LIMIT = 95;

    public static function send($mailable, string $to): void
    {
        $mailer = self::resolveMailer();

        try {
            Mail::mailer($mailer)->to($to)->send($mailable);
            self::increment($mailer);
        } catch (\Throwable $e) {
            Log::error("Mail failed on {$mailer}", ['error' => $e->getMessage()]);

            // Try next mailer on failure
            $fallback = self::nextMailer($mailer);
            Mail::mailer($fallback)->to($to)->send($mailable);
            self::increment($fallback);
        }
    }

    public static function queue($mailable, string $to): void
    {
        $mailer = self::resolveMailer();
        Mail::mailer($mailer)->to($to)->queue($mailable);
        self::increment($mailer);
    }

    private static function resolveMailer(): string
    {
        $resendCount      = (int) Cache::get('mail_count:resend', 0);
        $mailersendCount  = (int) Cache::get('mail_count:mailersend', 0);

        if ($resendCount < self::RESEND_LIMIT) {
            return 'resend';
        }

        if ($mailersendCount < self::MAILERSEND_LIMIT) {
            return 'mailersend';
        }

        // Both exhausted — reset and start over (or throw/alert)
        Log::warning('All mail quotas exhausted, resetting counts.');
        Cache::put('mail_count:resend', 0);
        Cache::put('mail_count:mailersend', 0);

        return 'resend';
    }

    private static function nextMailer(string $current): string
    {
        return $current === 'resend' ? 'mailersend' : 'resend';
    }

    private static function increment(string $mailer): void
    {
        $key = "mail_count:{$mailer}";
        Cache::increment($key);
    }

    public static function counts(): array
    {
        return [
            'resend'      => Cache::get('mail_count:resend', 0),
            'mailersend'  => Cache::get('mail_count:mailersend', 0),
        ];
    }

    public static function resetCounts(): void
    {
        Cache::put('mail_count:resend', 0);
        Cache::put('mail_count:mailersend', 0);
    }
}