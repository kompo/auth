<?php

if(!function_exists('throwValidationError')) {
    function throwValidationError($key, $message)
    {
        throw \Illuminate\Validation\ValidationException::withMessages([
            $key => [__($message)],
        ]);
    }
}

if(!function_exists('throwValidationConfirmation')) {
    function throwValidationConfirmation($message)
    {
        abort(449, $message);
    }
}

if(!function_exists('balanceLockedMessage')) {
    function balanceLockedMessage($date)
    {
        return __('finance.balance-locked', ['date' => $date]); // You can translate this using this syntax 'balance-locked' => 'Your balance is locked until :date.',
    }
}
