<?php

namespace App\Providers;

use App\Install\Installation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A fresh upload has no .env, database or app key yet; the web installer needs none of them.
        if (! $this->app->runningInConsole() && ! Installation::isInstalled()) {
            Installation::prepare();
        }
    }

    public function boot(): void
    {
        // Before the wizard has run the site may still be on plain http; do not force https links then.
        if ($this->app->isProduction() && Installation::isInstalled()) {
            URL::forceScheme('https');
        }

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
    }
}
