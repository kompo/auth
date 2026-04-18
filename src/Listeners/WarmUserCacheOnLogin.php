<?php

namespace Kompo\Auth\Listeners;

use Illuminate\Auth\Events\Login;
use Kompo\Auth\Teams\Cache\UserContextCache;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;

class WarmUserCacheOnLogin
{
    public function __construct(
        private PermissionResolverInterface $resolver,
        private UserContextCache $context,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (!$user || !isset($user->id)) {
            return;
        }

        try {
            $this->context->isSuperAdmin(
                $user->id,
                fn() => method_exists($user, 'isSuperAdmin') ? $user->isSuperAdmin() : false
            );

            $this->resolver->getUserPermissionsOptimized($user->id);
            $this->resolver->getAllAccessibleTeamsForUser($user->id);
        } catch (\Throwable $e) {
            \Log::warning('WarmUserCacheOnLogin failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
            ]);
        }
    }
}
