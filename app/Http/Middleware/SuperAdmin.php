<?php

namespace App\Http\Middleware;

use App\Services\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperAdmin()) {
            Audit::record($request->user(), 'access.denied', $request->path(), null, [], 'denied');
            abort(403);
        }

        return $next($request);
    }
}
