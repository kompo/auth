<?php

namespace Kompo\Auth\Translator;

use Illuminate\Translation\Translator;

class LoggedTranslator extends Translator
{
        /**
     * Handle a missing translation key.
     *
     * @param  string  $key
     * @param  array  $replace
     * @param  string|null  $locale
     * @param  bool  $fallback
     * @return string
     */
    protected function handleMissingTranslationKey($key, $replace, $locale, $fallback)
    {
        if (! $this->handleMissingTranslationKeys ||
            ! isset($this->missingTranslationKeyCallback)) {
            return $key;
        }

        // Prevent infinite loops...
        $this->handleMissingTranslationKeys = false;

        $key = call_user_func(
            $this->missingTranslationKeyCallback,
            $key, $replace, $locale, $fallback
        ) ?? $key;

        $this->handleMissingTranslationKeys = true;

        return $key;
    }
}