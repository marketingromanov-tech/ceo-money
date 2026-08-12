<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware(\App\Http\Middleware\EnsureUserIsActive::class);
        Livewire::addPersistentMiddleware(\App\Http\Middleware\EnsureAdminMfa::class);

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        foreach ([
            'deposit-request' => [5, 10], 'withdrawal-request' => [5, 10],
            'investor-wallet' => [5, 60], 'support-ticket' => [5, 60],
            'support-message' => [20, 60], 'dividend-capitalization' => [5, 10],
            'deposit-mark-paid' => [10, 10],
        ] as $name => [$attempts, $minutes]) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinutes($minutes, $attempts)->by((string) ($request->user()?->id ?? $request->ip())));
        }
    }
}
