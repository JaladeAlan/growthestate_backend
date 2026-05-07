<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SanctionsScreeningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScreenUserJob implements ShouldQueue
{
    use Queueable;

    public int   $tries         = 3;
    public int   $timeout       = 60;       // screening can be slow on large lists
    public int   $maxExceptions = 1;        // stop retrying on non-transient exceptions
    public array $backoff       = [30, 120]; // wait 30s, then 2min between retries

    public function __construct(
        public User   $user,
        public string $trigger = 'scheduled',
    ) {}

    public function handle(SanctionsScreeningService $service): void
    {
        $service->screen($this->user, $this->trigger);
    }
}

