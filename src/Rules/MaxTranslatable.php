<?php

namespace Kompo\Auth\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaxTranslatable implements ValidationRule
{
    protected $maxColLength;
    protected $locales;

    public function __construct(int $maxColLength)
    {
        $this->maxColLength = $maxColLength;
        $this->locales = config('kompo.locales');
    }

    protected function calculateMaxLengthPerLang()
    {
        return ($this->maxColLength - $this->getJsonMinLength()) / count($this->locales);
    }

    protected function getJsonMinLength()
    {
        $json = json_encode(collect($this->locales)->mapWithKeys(function ($lang, $key) {
            return [$key => ""];
        })->toArray());

        return strlen($json);
    }

    protected function roundToNearestTen(int $number)
    {
        return floor($number / 10) * 10;
    }

    protected function getRealLength(string $string)
    {
        return strlen(trim(json_encode($string), '"'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value == null) {
            return;
        }

        if (!is_array($value) && !is_string($value)) {
            $fail(__("rules.max-translatable-must-be-array-or-string", ['key' => __($attribute)]));
            return;
        }

        if (is_string($value)) { //If it's a string, we validate it as an array with only one language
            $value = $this->parseStringToArray($value);
        }

        $maxLengthPerLang = $this->roundToNearestTen($this->calculateMaxLengthPerLang());

        foreach ($this->locales as $key => $lang) {
            if (key_exists($key, $value) && $this->getRealLength($value[$key]) > $maxLengthPerLang) {
                $fail(__("rules.max-translatable", ['key' => __($attribute . '.' . $key), 'maxLength' => $maxLengthPerLang]));
            }
        }
    }

    protected function parseStringToArray(string $value)
    {
        return [
            collect($this->locales)->keys()->first() => $value
        ];
    }
}
