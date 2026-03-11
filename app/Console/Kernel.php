<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
    use App\Jobs\GenerateDailyPortfolioSnapshot;

class Kernel extends ConsoleKernel
{
    protected function schedule($schedule)
    {
        $schedule->job(new GenerateDailyPortfolioSnapshot)
            ->dailyAt('23:55')
            ->onOneServer()
            ->withoutOverlapping();
    }
}
