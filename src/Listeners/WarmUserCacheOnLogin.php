<?php

namespace Kompo\Auth\Listeners;

use Illuminate\Auth\Events\Login;
use Kompo\Auth\Teams\Cache\UserContextCache;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;

class WarmUserCacheOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (!$user || !isset($user->id)) {
            return;
        }

        if (!app()->bound(PermissionResolverInterface::class) || !app()->bound(UserContextCache::class)) {
            return;
        }

        try {
            $context = app(UserContextCache::class);
            $resolver = app(PermissionResolverInterface::class);

            $context->isSuperAdmin(
                $user->id,
                fn() => method_exists($user, 'isSuperAdmin') ? $user->isSuperAdmin() : false
            );

            $resolver->getUserPermissionsOptimized($user->id);
            $resolver->getAllAccessibleTeamsForUser($user->id);
        } catch (\Throwable $e) {
            \Log::warning('WarmUserCacheOnLogin failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
            ]);
        }
    }
}
