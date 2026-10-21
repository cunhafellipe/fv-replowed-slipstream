<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        Event::listen(Login::class, function (Login $event): void {
            if (!$event->user instanceof User) {
                return;
            }

            $event->user->forceFill([
                'last_login_at' => now(),
            ])->saveQuietly();
        });

        // The Pulse dashboard exposes operational information and must remain
        // available only to users who can access the existing admin area.
        Gate::define('viewPulse', function (User $user): bool {
            return (bool) $user->is_admin;
        });

        // Keep chat polling from consuming a player's message-send allowance.
        foreach (['chat-history' => 60, 'chat-send' => 10, 'chat-unread' => 60, 'chat-mark-read' => 30] as $name => $perMinute) {
            RateLimiter::for($name, static fn (Request $request): Limit => Limit::perMinute($perMinute)
                ->by($name.':'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
    }
}
