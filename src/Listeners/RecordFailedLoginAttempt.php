<?php

namespace Kompo\Auth\Listeners;

use Illuminate\Auth\Events\Failed;
use Kompo\Auth\Models\LoginAttempt;

class RecordFailedLoginAttempt
{
    public function handle(Failed $event)
    {
        $loginAttempt = new LoginAttempt();
        $loginAttempt->ip = request()->ip();
        $loginAttempt->email = $event->credentials['email'] ?? null;
        $loginAttempt->login_type = LoginAttempt::TYPE_LOCAL;
        $loginAttempt->success = false;
        $loginAttempt->save();
    }
}