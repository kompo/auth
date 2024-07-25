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
