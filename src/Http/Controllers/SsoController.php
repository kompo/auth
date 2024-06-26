<?php

namespace Kompo\Auth\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Kompo\Auth\Models\User;
use Laravel\Socialite\Facades\Socialite;

class SsoController extends Controller
{
    public function login(string $service)
    {
        return Socialite::driver($service)->redirect();
    }

    public function callback(string $service)
    {
        try{
            $socialUser = Socialite::driver($service)->user();
        } catch (\Exception $e) {
            abort(403, 'SSO login failed.');
        }

        $user = User::where('email', $socialUser->email)->first();

        if(!$user) {
            $user = User::create([
                'email' => $socialUser->email,
                'name' => $socialUser->name,
                'first_name' => $socialUser->user['given_name'] ?? '',
                'last_name' => $socialUser->user['family_name'] ?? '',
                'password' => \Hash::make(\Str::random(24)),
            ]);

            $user->createPersonalTeamAndOwnerRole();

            fireRegisteredEvent($user);
        }

        if(!$user->email_verified_at) {
            $user->email_verified_at = now();
        }

        $user->save();

        Auth::login($user);
     
        return redirect()->route('dashboard');
    }
}