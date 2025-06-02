<?php

namespace Kompo\Auth\Commands;

use Illuminate\Console\Command;
use Kompo\Auth\Models\Teams\PermissionCacheManager;

class OptimizePermissionCacheCommand extends Command
{
    protected $signature = 'permissions:optimize-cache 
                           {--warm : Warm cache for critical users}
                           {--clear : Clear all permission cache}
                           {--stats : Show cache statistics}
                           {--user-id=* : Specific user IDs to warm}';
    
    protected $description = 'Optimize permission cache for better performance';
    
    public function handle(PermissionCacheManager $cacheManager)
    {
        if ($this->option('clear')) {
            $this->clearCache($cacheManager);
            return;
        }
        
        if ($this->option('stats')) {
            $this->showStats($cacheManager);
            return;
        }
        
        if ($this->option('warm')) {
            $this->warmCache($cacheManager);
            return;
        }
        
        $userIds = $this->option('user-id');
        if (!empty($userIds)) {
            $this->warmSpecificUsers($cacheManager, $userIds);
            return;
        }
        
        $this->info('Use --help to see available options');
    }
    
    private function clearCache(PermissionCacheManager $cacheManager): void
    {
        $this->info('Clearing permission cache...');
        $cacheManager->clearAllCache();
        $this->info('✅ Permission cache cleared successfully');
    }
    
    private function warmCache(PermissionCacheManager $cacheManager): void
    {
        $this->info('Warming permission cache for critical users...');
        
        $progressBar = $this->output->createProgressBar();
        $progressBar->start();
        
        $warmed = $cacheManager->warmCriticalUserCache();
        
        $progressBar->finish();
        $this->newLine();
        $this->info("✅ Warmed cache for {$warmed} users");
    }
    
    private function warmSpecificUsers(PermissionCacheManager $cacheManager, array $userIds): void
    {
        $this->info('Warming cache for specific users...');
        
        $progressBar = $this->output->createProgressBar(count($userIds));
        
        foreach ($userIds as $userId) {
            try {
                $cacheManager->warmUserCache((int)$userId);
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->error("Failed to warm cache for user {$userId}: " . $e->getMessage());
            }
        }
        
        $progressBar->finish();
        $this->newLine();
        $this->info('✅ Cache warming completed');
    }
    
    private function showStats(PermissionCacheManager $cacheManager): void
    {
        $stats = $cacheManager->getCacheStats();
        
        $this->table(['Metric', 'Value'], [
            ['Cache Hits', number_format($stats['hits'] ?? 0)],
            ['Cache Misses', number_format($stats['misses'] ?? 0)],
            ['Hit Rate', $this->calculateHitRate($stats)],
            ['Memory Usage', $this->formatBytes($stats['memory_usage'] ?? 0)],
            ['Last Clear', $stats['last_clear'] ?? 'Never']
        ]);
    }
    
    private function calculateHitRate(array $stats): string
    {
        $hits = $stats['hits'] ?? 0;
        $misses = $stats['misses'] ?? 0;
        $total = $hits + $misses;
        
        if ($total === 0) {
            return 'N/A';
        }
        
        return number_format(($hits / $total) * 100, 2) . '%';
    }
    
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