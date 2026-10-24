<?php

namespace App\Http\Middleware;

use App\Install\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the wizard has run, every page leads to it. */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Installation::isInstalled() || $request->is('install', 'install/*', 'up')) {
            return $next($request);
        }

        return redirect()->route('install.start');
    }
}
