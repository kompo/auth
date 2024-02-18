<?php

/* Transformers */
function tinyintToBool($value): string
{
    return $value == 1 ? 'Yes' : 'No';
}

function toRounded($value, $decimals = 2): string
{
    return round($value, $decimals);
}

