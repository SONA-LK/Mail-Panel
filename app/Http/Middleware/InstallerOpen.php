<?php

namespace App\Http\Middleware;

use App\Install\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The wizard does not exist once the panel is installed. */
class InstallerOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Installation::isInstalled(), 404);

        return $next($request);
    }
}
