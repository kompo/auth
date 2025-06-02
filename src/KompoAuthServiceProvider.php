<?php

namespace Kompo\Auth;

use Condoedge\Utils\Kompo\Common\Modal;
use Condoedge\Utils\Kompo\Common\Query;
use Condoedge\Utils\Models\ModelBase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Kompo\Auth\Common\Plugins\HasAuthorizationUtils;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Auth\Commands\WarmTeamHierarchyCache;
use Kompo\Auth\Teams\TeamHierarchyService;

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


        //$this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ka');
        $this->loadJSONTranslationsFrom(__DIR__.'/../resources/lang');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kompo-auth');

        //Usage: php artisan vendor:publish --provider="Kompo\KompoServiceProvider"
        $this->publishes([
            __DIR__.'/../../config/kompo.php' => config_path('kompo.php'),
            __DIR__ . '/../resources/js' => resource_path('js/vendor/kompo'),
        ]);

        //Usage: php artisan vendor:publish --tag="files-icons"
        $this->publishes([
            __DIR__.'/../resources/icons' => public_path('icons'),
        ], 'files-icons');

        //Usage: php artisan vendor:publish --tag="kompo-auth-config"
        $this->publishes([
            __DIR__.'/../config/kompo-auth.php' => config_path('kompo-auth.php'),
        ], 'kompo-auth-config');

        $this->loadConfig();

        $this->loadRelationsMorphMap();

        $this->loadListeners();

        $this->loadMiddlewares();

        $this->loadCommands();
        $this->loadCrons();

        Cache::macro('rememberWithTags', function ($tags, $key, $ttl, $callback) {
            if (Cache::supportsTags()) {
                return Cache::tags($tags)->remember($key, $ttl, $callback);
            }

            return Cache::remember($key, $ttl, $callback);
        });

        Cache::macro('flushTags', function ($tags, $forceAll = true) {
            if (Cache::supportsTags()) {
                return Cache::tags($tags)->flush();
            }

            return $forceAll ? Cache::flush() : null;
        });

        Cache::macro('forgetTagsPattern', function ($tags, $pattern, $forceAll = true) {
            if (Cache::supportsTags()) {
                return Cache::tags($tags)->forget($pattern);
            }

            return $forceAll ? Cache::flush() : null;
        });

        // It seems as is not needed because we can use handleMissingKeysUsing instead of overriding the translator
        // $this->overrideTranslator();

        app('translator')->handleMissingKeysUsing(function ($key) {
            $hasTranslatableSyntax = preg_match('/^([a-zA-Z]*\.[a-zA-Z]*)+$/', $key);

            if ($hasTranslatableSyntax) {
                Log::warning("MISSING TRANSLATION KEY: $key");
            }
        });

        $this->app->bind(USER_MODEL_KEY, function () {
            return new (config('kompo-auth.user-namespace'));
        });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->loadHelpers();

        // We must put it here instead of boot because of the order of loading
        ModelBase::setPlugins([ HasSecurity::class ]);

        Query::setPlugins([ HasAuthorizationUtils::class ]);
        Form::setPlugins([ HasAuthorizationUtils::class ]);
        Modal::setPlugins([ HasAuthorizationUtils::class ]);

        // Bind security bypass service
        $this->app->singleton('kompo-auth.security-bypass', function ($app) {
            return function() {
                if (app()->runningInConsole()) {
                    return true;
                }

                if (auth()->user()?->isSuperAdmin()) {
                    return true;
                }
                
                return config('kompo-auth.security.bypass-security', false);
            };
        });

        //Best way to load routes. This ensures loading at the very end (after fortifies' routes for ex.)
        $this->booted(function () {
            \Route::middleware('web')->group(__DIR__.'/../routes/web.php');
        });

        $this->app->bind('notification-model', function () {
            return new (config('kompo-auth.notification-model-namespace'));
        });

        $this->app->bind('team-model', function () {
            return new (config('kompo-auth.team-model-namespace'));
        });

        $this->app->bind('role-model', function () {
            return new (config('kompo-auth.role-model-namespace'));
        });

        $this->app->singleton(TeamHierarchyService::class);
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
            config('kompo-auth.team-model-namespace') => \Kompo\Auth\Policies\TeamPolicy::class,
            \App\Models\User::class => \Kompo\Auth\Policies\UserPolicy::class,
        ];

        foreach ($policies as $key => $value) {
            \Gate::policy($key, $value);
        }
    }

    protected function loadConfig()
    {
        $dirs = [
            'kompo-auth' => __DIR__.'/../config/kompo-auth.php',
            'kompo' => __DIR__. '/../config/kompo.php'
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
            'team' => config('kompo-auth.team-model-namespace'),
        ]);
    }

    /**
     * Loads the listeners.
     */
    protected function loadListeners()
    {
        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
        });

        Event::listen(\Illuminate\Auth\Events\Login::class, \Kompo\Auth\Listeners\RecordSuccessLoginAttempt::class);
        Event::listen(\Illuminate\Auth\Events\Failed::class, \Kompo\Auth\Listeners\RecordFailedLoginAttempt::class);
    }

    protected function loadMiddlewares()
    {
        $this->app['router']->aliasMiddleware('sso.validate-driver', \Kompo\Auth\Http\Middleware\ValidateSsoDriver::class);
    }

    protected function loadCommands()
    {
        $this->commands([
            WarmTeamHierarchyCache::class,
        ]);
    }

    protected function loadCrons()
    {
        $schedule = $this->app->make(Schedule::class);
    }
}
