<?php

namespace App\Console\Commands;

use App\Jobs\ScreenUserJob;
use App\Models\User;
use Illuminate\Console\Command;

class RescreenUsers extends Command
{
    protected $signature   = 'sanctions:rescreen {--days=30 : rescreen users not checked in this many days}';
    protected $description = 'Re-screen all users against the latest sanctions lists';

    public function handle(): int
    {
        $days  = (int) $this->option('days');
        $query = User::where(function ($q) use ($days) {
            $q->whereNull('last_screened_at')
              ->orWhere('last_screened_at', '<', now()->subDays($days));
        })->whereNotIn('screening_status', ['blocked']); // don't waste API on already blocked

        $total = $query->count();
        $this->info("Queuing {$total} users for re-screening…");

        $query->chunkById(200, function ($users) {
            foreach ($users as $user) {
                ScreenUserJob::dispatch($user, 'scheduled')->onQueue('default');
            }
        });

        $this->info("Done. Jobs dispatched to queue.");
        return self::SUCCESS;
    }
}