<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Sync OFAC and UN lists daily at 2am (they update daily)
Schedule::command('sanctions:sync --source=ofac')->dailyAt('02:00');
Schedule::command('sanctions:sync --source=un')->dailyAt('02:30');

Schedule::command('sanctions:sync --source=eu')->dailyAt('02:45');

// Re-screen all users monthly
Schedule::command('sanctions:rescreen --days=30')->monthlyOn(1, '04:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();
