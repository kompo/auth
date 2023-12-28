<?php

function throwValidationError($key, $message)
{
	throw \Illuminate\Validation\ValidationException::withMessages([
       $key => [__($message)],
    ]);
}

function throwValidationConfirmation($message)
{
    abort(449, $message);
}

function balanceLockedMessage($date)
{
    return __('translate.finance.balance-locked', ['date' => $date]); // You can translate this using this syntax 'balance-locked' => 'Your balance is locked until :date.',
}
