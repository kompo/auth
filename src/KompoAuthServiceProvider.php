<?php

namespace Kompo\Auth;

use Illuminate\Support\ServiceProvider;

class KompoAuthServiceProvider extends ServiceProvider
{
    use \Kompo\Routing\Mixins\ExtendsRoutingTrait;

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadHelpers();

        $this->registerPolicies();

        $this->extendRouting(); //otherwise Route::layout doesn't work

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->loadJSONTranslationsFrom(__DIR__.'/../../resources/lang');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

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

    protected function loadHelpers()
    {
        $helpersDir = __DIR__.'/Helpers';

        $autoloadedHelpers = collect(\File::allFiles($helpersDir))->map(fn($file) => $file->getRealPath());

        $packageHelpers = [
        ];

        $autoloadedHelpers->concat($packageHelpers)->each(function ($path) {
            if (file_exists($path)) {
                require_once $path;
            }
        });
    }

    protected function registerPolicies()
    {
        $policies = [
            \Kompo\Auth\Models\Teams\Team::class => \Kompo\Auth\Policies\TeamPolicy::class,
        ];

        foreach ($policies as $key => $value) {
            \Gate::policy($key, $value);
        }
    }
}
