<?php

namespace Kompo\Auth\Listeners;

use Illuminate\Auth\Events\Login;
use Kompo\Auth\Models\LoginAttempt;

class RecordSuccessLoginAttempt
{
    public function handle(Login $event): void
    {
        $loginAttempt = new LoginAttempt();
        $loginAttempt->ip = request()->ip();
        $loginAttempt->email = $event->user->email ?? null;
        $loginAttempt->login_type = LoginAttempt::TYPE_LOCAL;
        $loginAttempt->success = true;
        $loginAttempt->save();
    }
}
