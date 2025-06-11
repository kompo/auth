<?php

namespace Kompo\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kompo\Auth\Models\Plugins\HasSecurity;

class DisableAutomaticSecurityMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        HasSecurity::enterBypassContext();

        return $next($request);
    }
}
