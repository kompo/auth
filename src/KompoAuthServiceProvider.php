<?php

namespace Kompo\Auth;

use Illuminate\Database\Eloquent\Relations\Relation;
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

        //$this->loadRoutesFrom(__DIR__.'/../routes/web.php');


        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ka');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kompo-auth');

        //Usage: php artisan vendor:publish --provider="Kompo\KompoServiceProvider"
        $this->publishes([
            __DIR__.'/../../config/kompo.php' => config_path('kompo.php'),
        ]);

        //Usage: php artisan vendor:publish --tag="kompo-auth-config"
        $this->publishes([
            __DIR__.'/../config/kompo-auth.php' => config_path('kompo-auth.php'),
            __DIR__ . '/../config/kompo-files.php' => config_path('kompo-files.php'),
            __DIR__ . '/../config/kompo-tags.php' => config_path('kompo-tags.php'),
        ], 'kompo-auth-config');

        $this->loadConfig();

        $this->loadRelationsMorphMap();
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //Best way to load routes. This ensures loading at the very end (after fortifies' routes for ex.)
        $this->booted(function () {
            \Route::middleware('web')->group(__DIR__.'/../routes/web.php');
        });
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

    protected function loadConfig()
    {
        $dirs = [
            'kompo-auth' => __DIR__.'/../config/kompo-auth.php',
            'kompo-files' => __DIR__.'/../config/kompo-files.php',
            'kompo-tags' => __DIR__.'/../config/kompo-tags.php',
        ];

        foreach ($dirs as $key => $path) {
            $this->mergeConfigFrom($path, $key);
        }
    }
    
    /**
     * Loads a relations morph map.
     */
    protected function loadRelationsMorphMap()
    {
        Relation::morphMap([
            'team' => \Kompo\Auth\Models\Teams\Team::class,
            'file' => \Kompo\Auth\Models\Files\File::class,
        ]);
    }
}
