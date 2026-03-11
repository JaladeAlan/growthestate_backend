<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use App\Services\GeoService;
use App\Models\LandPriceHistory;
use App\Models\User;
use App\Observers\LandPriceHistoryObserver;
use App\Providers\JwtUserProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
         $this->app->singleton(GeoService::class);
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url') . "/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        LandPriceHistory::observe(LandPriceHistoryObserver::class);

        // Register a custom user provider so that JWT token resolution
        // uses `uid` (e.g. "USR-XCV7PZ") instead of `id` (bigint).
        Auth::provider('jwt-eloquent', function ($app, array $config) {
            return new JwtUserProvider(
                $app['hash'],
                $config['model'] ?? User::class
            );
        });
    }
}