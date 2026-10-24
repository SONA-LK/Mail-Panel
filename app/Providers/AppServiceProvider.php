<?php

namespace App\Providers;

use App\Install\Installation;
use App\Services\Theme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A fresh upload has no .env, database or app key yet; the web installer needs none of them.
        if (! $this->app->runningInConsole() && ! Installation::isInstalled()) {
            Installation::prepare();
        }

        $this->app->singleton(Theme::class);
    }

    public function boot(): void
    {
        // Before the wizard has run the site may still be on plain http; do not force https links then.
        if ($this->app->isProduction() && Installation::isInstalled()) {
            URL::forceScheme('https');
        }

        // The two limits need different keys, or they would share one counter and the hourly cap could never fire.
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(10)->by('m:'.$r->ip()), Limit::perHour(40)->by('h:'.$r->ip())]);

        // Reading is free; changing things is capped per person, which slows a hijacked session down.
        RateLimiter::for('writes', fn (Request $r) => $r->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(config('panel.security.writes_per_minute'))->by('w:'.($r->user()?->id ?? $r->ip())));

        View::share('theme', app(Theme::class));

        // Number shown next to Approvals in the menu (super admins only).
        View::composer('layouts.app', function ($view) {
            $waiting = 0;
            if (auth()->user()?->isSuperAdmin()) {
                $waiting = rescue(fn () => \App\Models\ApprovalRequest::pending()->count(), 0, false);
            }
            $view->with('waiting', $waiting);
        });
    }
}
