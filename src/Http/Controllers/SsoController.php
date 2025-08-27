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
            return $this->logErrorAndRedirect($e);
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

    protected function logErrorAndRedirect($error)
    {
        \Log::warning('SSO ERROR');
        \Log::warning($error->getMessage());

        if (auth()->user()) { //for some reason we are logged in by azure ?? ..
            \Auth::guard()->logout();
        }

        return redirect()->route('login')
            ->with('status', __('translate.error-translations.issue-with-sso'));
    }
}