<?php

namespace App\Providers;

use App\Push\FcmPushSender;
use App\Push\LogPushSender;
use App\Push\PushSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolved by driver name rather than by environment: a staging box
        // that should send real push and a production box that should not are
        // both legitimate, and tying this to APP_ENV makes neither expressible.
        $this->app->singleton(PushSender::class, function (): PushSender {
            return match (config('hashbuddy.push.driver')) {
                'fcm' => new FcmPushSender,
                default => new LogPushSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sending is limited per traveller, not per IP: everyone on airport
        // wifi shares an address, and an IP limit would have one chatty group
        // silence every other traveller in the terminal.
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(
            (int) config('hashbuddy.chat.rate_limit_per_minute', 30),
        )->by($request->user()?->id ?: $request->ip()));

        // Ringing someone repeatedly is harassment, so this is deliberately
        // tighter than chat. A legitimate caller redials once or twice.
        RateLimiter::for('calls', fn (Request $request) => Limit::perMinute(5)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
