<?php

namespace Kompo\Auth\Auth\Actions;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kompo\Auth\Facades\UserModel;
use Laravel\Fortify\Fortify;

class EnsureUserIsNotBlocked
{
    public function handle(Request $request, \Closure $next)
    {
        $username = Fortify::username();

        $user = UserModel::where($username, $request->input($username))->withoutGlobalScopes()->first();

        if ($user && ($user->isBlocked() || $user->isBanned())) {
            throw ValidationException::withMessages([
                'error_field' => trans('translate.auth.blocked-user-explanation', ['username' => $user->{$username}]),
            ]);
        }

        return $next($request);
    }
}