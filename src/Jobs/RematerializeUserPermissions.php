<?php

namespace Kompo\Auth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kompo\Auth\Teams\Cache\UserPermissionSet;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;

class RematerializeUserPermissions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $userId) {}

    /**
     * Job-level deduplication key. Laravel's queue workers won't dedup by default,
     * but callers can use this for middleware-based deduplication (e.g. laravel-queue-rate-limit).
     */
    public function uniqueId(): string
    {
        return 'rematerialize-user-permissions.' . $this->userId;
    }

    public function handle(
        PermissionResolverInterface $resolver,
        UserPermissionSet $permissionSet,
    ): void {
        if (!$permissionSet->isSupported()) {
            return;
        }

        try {
            $permissions = $resolver->getUserPermissionsOptimized($this->userId);
            $permissionSet->materialize($this->userId, (array) $permissions, null);
        } catch (\Throwable $e) {
            \Log::warning('RematerializeUserPermissions failed: ' . $e->getMessage(), [
                'user_id' => $this->userId,
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('RematerializeUserPermissions exhausted retries', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
