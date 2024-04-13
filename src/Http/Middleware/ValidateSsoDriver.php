<?php

namespace Kompo\Auth\Http\Middleware;

class ValidateSsoDriver
{
    public function handle($request, $next)
    {
        $service = $request->route('service');

        if (
            !in_array($service, config('kompo-auth.sso-services')) ||
            !is_array(config('services.'.$service))
        ) abort(404, 'SSO service not enabled.');

        return $next($request);
    }
}