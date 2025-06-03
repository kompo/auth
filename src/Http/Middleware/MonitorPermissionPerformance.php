<?php

namespace Kompo\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MonitorPermissionPerformance
{
    private array $queryLog = [];
    private float $startTime;
    private int $startMemory;

    public function handle(Request $request, Closure $next)
    {
        // Only monitor in production with flag enabled
        if (!config('kompo-auth.monitor-performance', false)) {
            return $next($request);
        }

        $this->startMonitoring();

        $response = $next($request);

        $this->endMonitoring($request);

        return $response;
    }

    private function startMonitoring(): void
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);

        // Enable query log
        \DB::enableQueryLog();
    }

    private function endMonitoring(Request $request): void
    {
        $executionTime = (microtime(true) - $this->startTime) * 1000; // ms
        $memoryUsed = memory_get_usage(true) - $this->startMemory;
        $queries = \DB::getQueryLog();

        // Filter permission-related queries
        $permissionQueries = array_filter($queries, function ($query) {
            return str_contains($query['query'], 'permission') ||
                str_contains($query['query'], 'team_role') ||
                str_contains($query['query'], 'role');
        });

        // Log if performance thresholds exceeded
        if ($executionTime > 2000 || $memoryUsed > 50 * 1024 * 1024 || count($permissionQueries) > 20) {
            Log::warning('Permission performance threshold exceeded', [
                'url' => $request->url(),
                'user_id' => auth()->id(),
                'execution_time_ms' => round($executionTime, 2),
                'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
                'permission_queries' => count($permissionQueries),
                'total_queries' => count($queries),
                'slow_queries' => array_filter($permissionQueries, fn($q) => $q['time'] > 100)
            ]);
        }

        \DB::disableQueryLog();
    }
}
