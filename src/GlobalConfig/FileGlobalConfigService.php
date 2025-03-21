<?php

namespace Kompo\Auth\GlobalConfig;

class FileGlobalConfigService extends AbstractConfigService
{
    /**
     * @inheritDoc
     */
    public function forget(string $key)
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, $default = null)
    {
        return config('global-config.' . $key, $default);
    }

    /**
     * @inheritDoc
     */
    public function has(string $key)
    {
        return config('global-config.' . $key, null) !== null;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, $value)
    {
        return null;
    }
}
