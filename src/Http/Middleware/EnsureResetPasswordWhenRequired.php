<?php

namespace Kompo\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Response;

class EnsureResetPasswordWhenRequired
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && auth()->user()->must_reset_password_at?->isPast()) {
            $token = Password::createToken(auth()->user());
            $email = auth()->user()->email;

            auth()->logout();

            return redirect()->route('password.reset', compact(['token', 'email']))
                ->with('status', __('auth-password-reset-required'));
        }

        return $next($request);
    }
}
