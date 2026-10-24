<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only someone who can read storage/app/install-code.txt gets past the first screen. */
class InstallerUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('install.unlocked')) {
            return redirect()->route('install.start');
        }

        return $next($request);
    }
}
