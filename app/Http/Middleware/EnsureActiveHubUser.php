<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveHubUser
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(auth()->check() && ! auth()->user()->active, 403, 'Dieser Account ist deaktiviert.');

        return $next($request);
    }
}
