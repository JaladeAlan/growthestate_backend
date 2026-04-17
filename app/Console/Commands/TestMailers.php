<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TestMailers extends Command
{
    protected $signature = 'mail:test
                            {--to= : Email address to send test emails to}
                            {--mailer= : Test a specific mailer only (resend, mailtrap, mailersend, mailgun, postmark)}';

    protected $description = 'Send a test email through each configured mailer';

    private const MAILERS = ['resend', 'mailtrap', 'mailersend', 'mailgun', 'postmark'];

    public function handle(): int
    {
        $to = $this->option('to') ?? $this->ask('Send test emails to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$to}");
            return self::FAILURE;
        }

        $mailers = $this->option('mailer')
            ? [$this->option('mailer')]
            : self::MAILERS;

        // Validate mailer option
        foreach ($mailers as $mailer) {
            if (! in_array($mailer, self::MAILERS)) {
                $this->error("Unknown mailer: {$mailer}. Valid options: " . implode(', ', self::MAILERS));
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info("Sending test emails to: {$to}");
        $this->newLine();

        $results = [];

        foreach ($mailers as $mailer) {
            $this->output->write("  Testing <fg=cyan>{$mailer}</> ... ");

            try {
                Mail::mailer($mailer)
                    ->to($to)
                    ->send(new TestMail($mailer));

                $this->output->writeln('<fg=green>✓ sent</>');
                $results[$mailer] = 'passed';
            } catch (\Throwable $e) {
                $this->output->writeln('<fg=red>✗ failed</>');
                $this->output->writeln("    <fg=red>{$e->getMessage()}</>");
                $results[$mailer] = 'failed';
            }
        }

        $this->newLine();
        $this->table(
            ['Mailer', 'Result'],
            collect($results)->map(fn($result, $mailer) => [
                $mailer,
                $result === 'passed' ? '<fg=green>✓ passed</>' : '<fg=red>✗ failed</>',
            ])->values()->toArray()
        );

        $failed = collect($results)->filter(fn($r) => $r === 'failed')->count();

        $this->newLine();

        if ($failed === 0) {
            $this->info('All mailers passed.');
        } else {
            $this->warn("{$failed} mailer(s) failed. Check your credentials and config.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Inline Mailable — no Blade view needed
// ─────────────────────────────────────────────────────────────────────────────

class TestMail extends Mailable
{
    public function __construct(public readonly string $mailerName) {}

    public function build()
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject("[REU.ng] Test email via {$this->mailerName}")
                    ->html("
                        <p>This is a test email sent through <strong>{$this->mailerName}</strong>.</p>
                        <p>If you received this, the mailer is configured correctly.</p>
                        <p style='color:#888;font-size:12px;'>Sent at: " . now()->toDateTimeString() . " (" . config('app.timezone', 'UTC') . ")</p>
                    ");
    }
}