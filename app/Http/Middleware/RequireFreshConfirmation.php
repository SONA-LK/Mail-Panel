<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive changes need a confirmation from the last few minutes (second factor, or Google again for
 * people without one). A stolen browser session therefore cannot reset passwords, delete mailboxes or add
 * its own sign-in method just by being open. Reading pages is never blocked, only the change itself.
 */
class RequireFreshConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $minutes = (int) config('panel.security.step_up_minutes');

        if ($minutes <= 0 || $request->isMethodSafe() || now()->timestamp - (int) $request->session()->get('confirmed_at', 0) <= $minutes * 60) {
            return $next($request);
        }

        $back = url()->previous(route('dashboard'));
        // Only ever return to this site, whatever the Referer says.
        $request->session()->put('url.intended', parse_url($back, PHP_URL_HOST) === $request->getHost() ? $back : route('dashboard'));

        if ($request->expectsJson()) {
            return response()->json(['error' => 'confirm', 'redirect' => route('confirm.show')], 403);
        }

        return redirect()->route('confirm.show')
            ->with('warning', 'For your safety, confirm it is you. Then repeat what you were doing.');
    }
}
