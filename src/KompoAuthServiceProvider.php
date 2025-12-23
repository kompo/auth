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
use Kompo\Auth\Commands\CleanupRedundantHierarchyRoles;
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

        $this->loadJSONTranslationsFrom(__DIR__ . '/../resources/lang');

        if (config('kompo-auth.load-migrations', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
        
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'kompo-auth');

        $this->publishes([
            __DIR__ . '/../../config/kompo.php' => config_path('kompo.php'),
            __DIR__ . '/../resources/js' => resource_path('js/vendor/kompo'),
        ]);

        $this->publishes([
            __DIR__ . '/../resources/icons' => public_path('icons'),
        ], 'files-icons');

        $this->publishes([
            __DIR__ . '/../config/kompo-auth.php' => config_path('kompo-auth.php'),
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
            return new (config('kompo-auth.user-model-namespace'));
        });
    }

    /**
     * Register services.
     */
    public function register()
    {
        $this->loadHelpers();

        if (config('kompo-auth.root-security', false)) {
            // Register model plugins
            ModelBase::setPlugins([HasSecurity::class]);
            Query::setPlugins([HasAuthorizationUtils::class]);
            Form::setPlugins([HasAuthorizationUtils::class]);
            Modal::setPlugins([HasAuthorizationUtils::class]);

            // Register core services in correct order
            $this->registerCoreServices();
            $this->registerOptimizedPermissionServices();
            $this->registerSecurityServices();
        }

        // Register route loading
        $this->booted(function () {
            \Route::middleware('web')->group(__DIR__ . '/../routes/web.php');
        });
    }

    /**
     * Register core auth services
     */
    private function registerCoreServices(): void
    {
        // Security bypass service (highest priority)
        $this->app->singleton('kompo-auth.security-bypass', function ($app) {
            return function () {
                 if (app()->runningInConsole()) {
                    return true;
                }

                if (session()->isStarted() && config('kompo-auth.security.dont-check-if-not-logged-in', false) && !auth()->check()) {
                    return true;
                }

                if (auth()->user()?->isSuperAdmin()) {
                    return true;
                }

                if (isInBypassContext()) {
                    return true;
                }

                if (routeIsByPassed()) {
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
     * Register optimized permission services with proper dependency injection
     */
    private function registerOptimizedPermissionServices(): void
    {
        // Team hierarchy service (foundation service - no dependencies)
        $this->app->singleton(TeamHierarchyService::class, function ($app) {
            return new TeamHierarchyService();
        });

        // Permission resolver (depends on TeamHierarchyService)
        $this->app->singleton(PermissionResolver::class, function ($app) {
            return new PermissionResolver($app->make(TeamHierarchyService::class));
        });

        // Permission cache manager (depends on PermissionResolver)
        $this->app->singleton(PermissionCacheManager::class, function ($app) {
            return new PermissionCacheManager($app->make(PermissionResolver::class));
        });

        // Performance monitoring service
        $this->app->singleton('permission-performance-monitor', function () {
            return new class {
                private array $metrics = [];
                private array $queryLog = [];

                public function startTimer(string $operation): void
                {
                    $this->metrics[$operation] = [
                        'start_time' => microtime(true),
                        'start_memory' => memory_get_usage(true),
                        'query_count_start' => count(\DB::getQueryLog())
                    ];
                }

                public function endTimer(string $operation): array
                {
                    if (!isset($this->metrics[$operation])) {
                        return [];
                    }

                    $start = $this->metrics[$operation];
                    $currentQueries = \DB::getQueryLog();
                    $newQueries = array_slice($currentQueries, $start['query_count_start']);

                    $result = [
                        'execution_time_ms' => (microtime(true) - $start['start_time']) * 1000,
                        'memory_used_bytes' => memory_get_usage(true) - $start['start_memory'],
                        'queries_count' => count($newQueries),
                        'slow_queries' => array_filter($newQueries, fn($q) => $q['time'] > 100)
                    ];

                    // Clean up
                    unset($this->metrics[$operation]);

                    return $result;
                }

                public function getMetrics(): array
                {
                    return $this->metrics;
                }

                public function logSlowOperation(string $operation, array $metrics): void
                {
                    if ($metrics['execution_time_ms'] > 500 || $metrics['queries_count'] > 10) {
                        \Log::warning("Slow permission operation detected", [
                            'operation' => $operation,
                            'metrics' => $metrics,
                            'user_id' => auth()->id()
                        ]);
                    }
                }
            };
        });
    }

    /**
     * Register security services with proper dependency injection (Laravel standard)
     */
    private function registerSecurityServices(): void
    {
        // Register singleton services (stateless, shared across requests)
        $this->app->singleton(
            \Kompo\Auth\Models\Plugins\Services\SecurityBypassService::class
        );

        $this->app->singleton(
            \Kompo\Auth\Models\Plugins\Services\PermissionCacheService::class
        );

        // Register the factory as singleton (it manages service creation)
        $this->app->singleton(
            \Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory::class,
            function ($app) {
                return new \Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory(
                    $app->make(\Kompo\Auth\Models\Plugins\Services\SecurityBypassService::class),
                    $app->make(\Kompo\Auth\Models\Plugins\Services\PermissionCacheService::class)
                );
            }
        );
    }

    /**
     * Setup cache macros for better cache management
     */
    private function setupCacheMacros(): void
    {
        // Enhanced cache macros with better error handling
        Cache::macro('rememberWithTags', function ($tags, $key, $ttl, $callback) {
            try {
                if (Cache::supportsTags()) {
                    return Cache::tags($tags)->remember($key, $ttl, $callback);
                }
                return Cache::remember($key, $ttl, $callback);
            } catch (\Exception $e) {
                \Log::warning('Cache operation failed, executing callback directly', [
                    'key' => $key,
                    'tags' => $tags,
                    'error' => $e->getMessage()
                ]);
                return $callback();
            }
        });

        Cache::macro('flushTags', function ($tags, $forceAll = true) {
            try {
                if (Cache::supportsTags()) {
                    return Cache::tags($tags)->flush();
                }
                return $forceAll ? Cache::flush() : null;
            } catch (\Exception $e) {
                \Log::warning('Cache flush failed', [
                    'tags' => $tags,
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        });

        Cache::macro('forgetTagsPattern', function ($tags, $pattern, $forceAll = true) {
            try {
                if (Cache::supportsTags()) {
                    return Cache::tags($tags)->forget($pattern);
                }
                return $forceAll ? Cache::flush() : null;
            } catch (\Exception $e) {
                \Log::warning('Cache pattern forget failed', [
                    'tags' => $tags,
                    'pattern' => $pattern,
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        });

        // Intelligent cache warming with failure tolerance
        Cache::macro('warmIfMissing', function ($key, $ttl, $callback, $tags = []) {
            try {
                $store = Cache::supportsTags() && !empty($tags) ? Cache::tags($tags) : Cache::store();

                if ($store->missing($key)) {
                    $value = $callback();
                    $store->put($key, $value, $ttl);
                    return $value;
                }

                return $store->get($key);
            } catch (\Exception $e) {
                \Log::warning('Cache warm operation failed, executing callback', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
                return $callback();
            }
        });

        // Batch cache operations
        Cache::macro('putMany', function ($items, $ttl, $tags = []) {
            $store = Cache::supportsTags() && !empty($tags) ? Cache::tags($tags) : Cache::store();
            $failed = [];

            foreach ($items as $key => $value) {
                try {
                    $store->put($key, $value, $ttl);
                } catch (\Exception $e) {
                    $failed[] = $key;
                    \Log::warning("Failed to cache item: {$key}", ['error' => $e->getMessage()]);
                }
            }

            return $failed;
        });

    }

    /**
     * Setup performance monitoring with configurable thresholds
     */
    private function setupPerformanceMonitoring(): void
    {
        if (!config('kompo-auth.monitor-performance', false)) {
            return;
        }

        // Register global middleware for web routes
        $this->app['router']->pushMiddlewareToGroup('web', MonitorPermissionPerformance::class);

        // Setup memory threshold monitoring with configurable limits
        register_shutdown_function(function () {
            $memoryUsage = memory_get_peak_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            $threshold = config('kompo-auth.performance.memory_threshold', 0.9);

            if ($memoryUsage > ($memoryLimit * $threshold)) {
                Log::warning('High memory usage detected', [
                    'memory_used' => $this->formatBytes($memoryUsage),
                    'memory_limit' => $this->formatBytes($memoryLimit),
                    'usage_percentage' => round(($memoryUsage / $memoryLimit) * 100, 2),
                    'threshold_percentage' => $threshold * 100,
                    'user_id' => auth()->id(),
                    'url' => request()->url() ?? 'N/A',
                    'request_id' => request()->header('X-Request-ID', 'N/A')
                ]);
            }
        });

        // Setup query monitoring for permission-related operations
        if (config('kompo-auth.performance.monitor_queries', false)) {
            \DB::listen(function ($query) {
                if ($query->time > config('kompo-auth.performance.slow_query_threshold', 1000)) {
                    $isPermissionQuery = str_contains($query->sql, 'permission') ||
                        str_contains($query->sql, 'team_role') ||
                        str_contains($query->sql, 'role');

                    if ($isPermissionQuery) {
                        Log::warning('Slow permission query detected', [
                            'sql' => $query->sql,
                            'bindings' => $query->bindings,
                            'time' => $query->time,
                            'user_id' => auth()->id()
                        ]);
                    }
                }
            });
        }
    }

    /**
     * Load commands with error handling
     */
    protected function loadCommands(): void
    {
        $commands = [
            WarmTeamHierarchyCache::class,
            OptimizePermissionCacheCommand::class,
            CleanupRedundantHierarchyRoles::class,
        ];

        foreach ($commands as $command) {
            try {
                $this->commands([$command]);
            } catch (\Exception $e) {
                Log::warning("Failed to register command: {$command}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Load cron jobs with better error handling
     */
    protected function loadCrons(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // Cache warming for critical users (with error handling)
            $schedule->command('permissions:optimize-cache --warm')
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground()
                ->onFailure(function () {
                    Log::error('Failed to run permission cache warming');
                });

            // Team hierarchy cache warming
            $schedule->command('teams:warm-hierarchy-cache')
                ->dailyAt('02:00')
                ->withoutOverlapping()
                ->runInBackground()
                ->onFailure(function () {
                    Log::error('Failed to run team hierarchy cache warming');
                });

            // Cleaning temp hierarchy roles
            $schedule->command('auth:cleanup-hierarchy-roles')
                ->dailyAt('01:00')
                ->withoutOverlapping()
                ->runInBackground()
                ->onFailure(function () {
                    Log::error('Failed to run team hierarchy cache warming');
                });

            // Clear old cache statistics and cleanup
            $schedule->call(function () {
                try {
                    app(PermissionCacheManager::class)->clearAllCache();

                    // Clear any orphaned cache keys
                    if (Cache::supportsTags()) {
                        Cache::tags(['permissions-v2-temp'])->flush();
                    }
                } catch (\Exception $e) {
                    Log::warning('Cache cleanup failed', ['error' => $e->getMessage()]);
                }
            })->daily()->name('permission-cache-cleanup');
        });
    }

    /**
     * Load configuration files with validation
     */
    protected function loadConfig(): void
    {
        $configs = [
            'kompo-auth' => __DIR__ . '/../config/kompo-auth.php',
            'kompo' => __DIR__ . '/../config/kompo.php',
            'fortify' => __DIR__ . '/../config/fortify.php',
            'services' => __DIR__ . '/../config/services.php',
            'impersonate' => __DIR__ . '/../config/laravel-impersonate.php',
        ];

        foreach ($configs as $key => $path) {
            if (file_exists($path)) {
                $this->mergeConfigFrom($path, $key);
            } else {
                Log::warning("Config file not found: {$path}");
            }
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
     * Load event listeners with optimized cache invalidation
     */
    protected function loadListeners(): void
    {
        // Socialite events
        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
        });

        // Auth events
        Event::listen(\Illuminate\Auth\Events\Login::class, \Kompo\Auth\Listeners\RecordSuccessLoginAttempt::class);
        Event::listen(\Illuminate\Auth\Events\Failed::class, \Kompo\Auth\Listeners\RecordFailedLoginAttempt::class);
    }

    /**
     * Load middleware
     */
    protected function loadMiddlewares(): void
    {
        $middlewares = [
            'sso.validate-driver' => \Kompo\Auth\Http\Middleware\ValidateSsoDriver::class,
            'monitor-permissions' => MonitorPermissionPerformance::class,
            'disable-automatic-security' => \Kompo\Auth\Http\Middleware\DisableAutomaticSecurityMiddleware::class,
        ];

        foreach ($middlewares as $alias => $class) {
            $this->app['router']->aliasMiddleware($alias, $class);
        }
    }

    /**
     * Register policies with error handling
     */
    protected function registerPolicies(): void
    {
        $policies = [
            config('kompo-auth.team-model-namespace') => \Kompo\Auth\Policies\TeamPolicy::class,
            \Kompo\Auth\Facades\UserModel::class => \Kompo\Auth\Policies\UserPolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            try {
                \Gate::policy($model, $policy);
            } catch (\Exception $e) {
                Log::warning("Failed to register policy for {$model}", [
                    'policy' => $policy,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Load helper files
     */
    protected function loadHelpers(): void
    {
        $helpersDir = __DIR__ . '/Helpers';

        if (!is_dir($helpersDir)) {
            return;
        }

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
        if ($limit === '-1') {
            return PHP_INT_MAX; // Unlimited
        }

        $unit = strtolower(substr($limit, -1));
        $amount = (int) substr($limit, 0, -1);

        return match ($unit) {
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
