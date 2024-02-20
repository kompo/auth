<?php

if (!function_exists('safeDivision')) {
    function safeDivision($numerator, $denominator)
    {
        if (!$denominator || (abs($denominator) < 0.00001)) {
            return 0;
        }

        return $numerator / $denominator;
    }
}