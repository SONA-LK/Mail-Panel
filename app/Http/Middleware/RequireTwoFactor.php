<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A role that must use a second factor can do nothing but set one up until it has. */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->requiresTwoFactor() && ! $user->hasTwoFactor()
            && ! $request->routeIs('security.*', 'confirm.*', 'logout', 'appearance.*')) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Set up a second sign-in step first.'], 403);
            }

            return redirect()->route('security.index')
                ->with('warning', 'Your role needs a second sign-in step. Add an authenticator app or a passkey below to continue.');
        }

        return $next($request);
    }
}
