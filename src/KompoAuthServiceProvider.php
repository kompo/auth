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
use Kompo\Auth\Commands\OptimizePermissionCacheCommand;
use Kompo\Auth\Commands\WarmTeamHierarchyCache;
use Kompo\Auth\Teams\TeamHierarchyService;
use Kompo\Auth\Http\Middleware\MonitorPermissionPerformance;
use Kompo\Auth\Teams\PermissionCacheManager;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Teams\PermissionResolver;

class KompoAuthServiceProvider extends ServiceProvider
{
    use \Kompo\Routing\Mixins\ExtendsRoutingTrait;

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        $this->loadHelpers();
        $this->registerPolicies();
        $this->extendRouting();
        
        $this->loadJSONTranslationsFrom(__DIR__.'/../resources/lang');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kompo-auth');

        $this->publishes([
            __DIR__.'/../../config/kompo.php' => config_path('kompo.php'),
            __DIR__ . '/../resources/js' => resource_path('js/vendor/kompo'),
        ]);

        $this->publishes([
            __DIR__.'/../resources/icons' => public_path('icons'),
        ], 'files-icons');

        $this->publishes([
            __DIR__.'/../config/kompo-auth.php' => config_path('kompo-auth.php'),
        ], 'kompo-auth-config');

        $this->loadConfig();
        $this->loadRelationsMorphMap();
        $this->loadListeners();
        $this->loadMiddlewares();
        $this->loadCommands();
        $this->loadCrons();
        $this->setupCacheMacros();
        $this->setupPerformanceMonitoring();

        // Setup missing translation handling
        app('translator')->handleMissingKeysUsing(function ($key) {
            $hasTranslatableSyntax = preg_match('/^([a-zA-Z]*\.[a-zA-Z]*)+$/', $key);
            if ($hasTranslatableSyntax) {
                Log::warning("MISSING TRANSLATION KEY: $key");
            }
        });

        // Bind user model
        $this->app->bind(USER_MODEL_KEY, function () {
            return new (config('kompo-auth.user-namespace'));
        });
    }

    /**
     * Register services.
     */
    public function register()
    {
        $this->loadHelpers();

        // Register model plugins
        ModelBase::setPlugins([HasSecurity::class]);
        Query::setPlugins([HasAuthorizationUtils::class]);
        Form::setPlugins([HasAuthorizationUtils::class]);
        Modal::setPlugins([HasAuthorizationUtils::class]);

        // Register core services
        $this->registerCoreServices();
        $this->registerPermissionServices();
        
        // Register route loading
        $this->booted(function () {
            \Route::middleware('web')->group(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Register core auth services
     */
    private function registerCoreServices(): void
    {
        // Security bypass service
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

        // Model bindings
        $this->app->bind('notification-model', function () {
            return new (config('kompo-auth.notification-model-namespace'));
        });

        $this->app->bind('team-model', function () {
            return new (config('kompo-auth.team-model-namespace'));
        });

        $this->app->bind('role-model', function () {
            return new (config('kompo-auth.role-model-namespace'));
        });
    }

    /**
     * Register optimized permission services
     */
    private function registerPermissionServices(): void
    {
        // Team hierarchy service
        $this->app->singleton(TeamHierarchyService::class);
        
        // Permission resolver
        $this->app->singleton(PermissionResolver::class, function ($app) {
            return new PermissionResolver($app->make(TeamHierarchyService::class));
        });
        
        // Permission cache manager
        $this->app->singleton(PermissionCacheManager::class);
        
        // Performance monitoring service
        $this->app->singleton('permission-performance-monitor', function () {
            return new class {
                private array $metrics = [];
                
                public function startTimer(string $operation): void
                {
                    $this->metrics[$operation] = [
                        'start_time' => microtime(true),
                        'start_memory' => memory_get_usage(true)
                    ];
                }
                
                public function endTimer(string $operation): array
                {
                    if (!isset($this->metrics[$operation])) {
                        return [];
                    }
                    
                    $start = $this->metrics[$operation];
                    
                    return [
                        'execution_time_ms' => (microtime(true) - $start['start_time']) * 1000,
                        'memory_used_bytes' => memory_get_usage(true) - $start['start_memory']
                    ];
                }
                
                public function getMetrics(): array
                {
                    return $this->metrics;
                }
            };
        });
    }

    /**
     * Setup cache macros for better cache management
     */
    private function setupCacheMacros(): void
    {
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
        
        // New macro for intelligent cache warming
        Cache::macro('warmIfMissing', function ($key, $ttl, $callback, $tags = []) {
            $store = Cache::supportsTags() && !empty($tags) ? Cache::tags($tags) : Cache::store();
            
            if ($store->missing($key)) {
                $value = $callback();
                $store->put($key, $value, $ttl);
                return $value;
            }
            
            return $store->get($key);
        });
    }

    /**
     * Setup performance monitoring
     */
    private function setupPerformanceMonitoring(): void
    {
        if (config('kompo-auth.monitor-performance', false)) {
            // Register global middleware
            $this->app['router']->pushMiddlewareToGroup('web', MonitorPermissionPerformance::class);

            // Setup memory threshold monitoring
            register_shutdown_function(function() {
                $memoryUsage = memory_get_peak_usage(true);
                $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
                
                if ($memoryUsage > ($memoryLimit * 0.9)) { // 90% threshold
                    Log::warning('High memory usage detected', [
                        'memory_used' => $this->formatBytes($memoryUsage),
                        'memory_limit' => $this->formatBytes($memoryLimit),
                        'usage_percentage' => round(($memoryUsage / $memoryLimit) * 100, 2),
                        'user_id' => auth()->id(),
                        'url' => request()->url() ?? 'N/A'
                    ]);
                }
            });
        }
    }

    /**
     * Load commands
     */
    protected function loadCommands(): void
    {
        $this->commands([
            WarmTeamHierarchyCache::class,
            OptimizePermissionCacheCommand::class,
        ]);
    }

    /**
     * Load cron jobs
     */
    protected function loadCrons(): void
    {
        $schedule = $this->app->make(Schedule::class);
        
        // Cache warming for critical users
        $schedule->command('permissions:optimize-cache --warm')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();
        
        // Clear old cache statistics
        $schedule->call(function () {
            app(PermissionCacheManager::class)->clearAllCache();
        })->daily();
    }

    /**
     * Load configuration files
     */
    protected function loadConfig(): void
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
     * Load relations morph map
     */
    protected function loadRelationsMorphMap(): void
    {
        Relation::morphMap([
            'team' => config('kompo-auth.team-model-namespace'),
        ]);
    }

    /**
     * Load event listeners
     */
    protected function loadListeners(): void
    {
        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
        });

        Event::listen(\Illuminate\Auth\Events\Login::class, \Kompo\Auth\Listeners\RecordSuccessLoginAttempt::class);
        Event::listen(\Illuminate\Auth\Events\Failed::class, \Kompo\Auth\Listeners\RecordFailedLoginAttempt::class);
        
        // Permission cache invalidation listeners
        Event::listen('eloquent.saved: ' . TeamRole::class, function ($teamRole) {
            app(PermissionCacheManager::class)->invalidateByChange('team_role_changed', [
                'user_ids' => [$teamRole->user_id]
            ]);
        });
        
        Event::listen('eloquent.deleted: ' . TeamRole::class, function ($teamRole) {
            app(PermissionCacheManager::class)->invalidateByChange('team_role_changed', [
                'user_ids' => [$teamRole->user_id]
            ]);
        });
    }

    /**
     * Load middleware
     */
    protected function loadMiddlewares(): void
    {
        $this->app['router']->aliasMiddleware('sso.validate-driver', \Kompo\Auth\Http\Middleware\ValidateSsoDriver::class);
        $this->app['router']->aliasMiddleware('monitor-permissions', MonitorPermissionPerformance::class);
    }

    /**
     * Register policies
     */
    protected function registerPolicies(): void
    {
        $policies = [
            config('kompo-auth.team-model-namespace') => \Kompo\Auth\Policies\TeamPolicy::class,
            \App\Models\User::class => \Kompo\Auth\Policies\UserPolicy::class,
        ];

        foreach ($policies as $key => $value) {
            \Gate::policy($key, $value);
        }
    }

    /**
     * Load helper files
     */
    protected function loadHelpers(): void
    {
        $helpersDir = __DIR__.'/Helpers';
        $autoloadedHelpers = collect(\File::allFiles($helpersDir))->map(fn($file) => $file->getRealPath());
        
        $autoloadedHelpers->each(function ($path) {
            if (file_exists($path)) {
                require_once $path;
            }
        });
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $unit = strtolower(substr($limit, -1));
        $amount = (int) substr($limit, 0, -1);
        
        return match($unit) {
            'g' => $amount * 1024 * 1024 * 1024,
            'm' => $amount * 1024 * 1024,
            'k' => $amount * 1024,
            default => $amount
        };
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        
        return number_format($bytes, 2) . ' ' . $units[$index];
    }
}
