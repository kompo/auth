<?php

namespace Kompo\Auth;

use Illuminate\Support\ServiceProvider;

class KompoAuthServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //$this->mergeConfigFrom(__DIR__.'/../../config/kompo.php', 'kompo');

        //$this->loadRoutesFrom(__DIR__.'/../../routes/web.php');

        $this->loadJSONTranslationsFrom(__DIR__.'/../../resources/lang');

        //$this->loadViewsFrom(__DIR__.'/../../resources/views', 'kompo');

         //Usage: php artisan vendor:publish --provider="Kompo\KompoServiceProvider"
        $this->publishes([
            __DIR__.'/../../config/kompo.php' => config_path('kompo.php'),
        ]);
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {

    }
}
