<?php

namespace Kompo\Auth\GlobalConfig;

abstract class AbstractConfigService implements GlobalConfigServiceContract
{
    public function getOrFail(string $key)
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new \Exception("Config key '$key' not found.");
        }

        return $value;
    }
}