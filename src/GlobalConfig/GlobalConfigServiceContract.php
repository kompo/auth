<?php

namespace Kompo\Auth\GlobalConfig;

interface GlobalConfigServiceContract
{
    public function get(string $key, $default = null);

    public function set(string $key, $value);

    public function has(string $key);

    public function forget(string $key);

    public function getOrFail(string $key);
}
