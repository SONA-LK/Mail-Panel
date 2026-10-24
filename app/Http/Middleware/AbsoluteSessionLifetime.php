<?php

namespace App\Http\Middleware;

use App\Services\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** The idle limit is SESSION_LIFETIME; this one caps the total age of a sign-in so a stolen cookie cannot live forever. */
class AbsoluteSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $session = $request->session();
        // Sessions that started before this check existed get their clock started now.
        $startedAt = (int) $session->get('login_at', 0) ?: tap(now()->timestamp, fn ($t) => $session->put('login_at', $t));
        $limit = config('panel.security.session_absolute_minutes') * 60;

        if ($limit > 0 && now()->timestamp - $startedAt > $limit) {
            Audit::record($request->user(), 'auth.session_expired', $request->user()->email, null, ['reason' => 'absolute_lifetime']);
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->route('login')->withErrors(['login' => 'Your session ended for security reasons. Please sign in again.']);
        }

        return $next($request);
    }
}
