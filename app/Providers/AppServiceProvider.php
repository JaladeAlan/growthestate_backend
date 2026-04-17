<?php
namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Mail;
use App\Mail\Transport\MailtrapTransport;
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
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url') . "/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        LandPriceHistory::observe(LandPriceHistoryObserver::class);

        Auth::provider('jwt-eloquent', function ($app, array $config) {
            return new JwtUserProvider(
                $app['hash'],
                $config['model'] ?? User::class
            );
        });

        // Mailtrap API transport
        Mail::extend('mailtrap', function (array $config) {
            return new MailtrapTransport(
                apiKey: $config['api_key'] ?? config('services.mailtrap.api_key'),
            );
        });
    }
}