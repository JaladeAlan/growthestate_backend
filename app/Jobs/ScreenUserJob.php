<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SanctionsScreeningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * All $tries exhausted (or a non-transient exception hit maxExceptions).
     * A silently-failed screening job means this user never got screened
     * and nothing else would notice — alert the same channel used for
     * PEP self-declarations so this gets manual follow-up instead of
     * quietly sitting in `failed_jobs`.
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('telegram')->warning(
            '⚠️ ScreenUserJob failed permanently — user is unscreened',
            [
                'user_id' => $this->user->id,
                'email'   => $this->user->email,
                'trigger' => $this->trigger,
                'error'   => $exception->getMessage(),
            ]
        );
    }
}