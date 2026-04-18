<?php

namespace Kompo\Auth\Teams\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\CacheKeyBuilder;

/**
 * Redis-set-backed permission check for O(1) userHasPermission.
 *
 * Two Redis sets per (user, teamIds, version):
 *  - user_deny.{uid}.v{N}.{teamKey}   : permission_key strings only (no type suffix).
 *  - user_allow.{uid}.v{N}.{teamKey}  : "{permission_key}|{type_value}" strings,
 *                                       expanded across all implied types at materialization.
 *
 * Materialization is idempotent. A sentinel key (`user_permset_ready.{uid}.v{N}.{teamKey}`)
 * indicates completion — using EXISTS on the sets themselves would miss the "user has no
 * permissions at all" case where both sets are legitimately empty.
 *
 * Falls back to null-returning when the cache driver is not Redis; callers must then
 * use the existing array-iteration path.
 */
class UserPermissionSet
{
    private const READY_TTL = 900; // match kompo-auth.cache.ttl default

    /** Per-request cache of "is this (uid,teamKey,version) materialized?" to avoid repeat EXISTS calls. */
    private array $readyRequestCache = [];

    public function __construct(private UserCacheVersion $versions) {}

    public function isSupported(): bool
    {
        try {
            return Cache::getStore() instanceof RedisStore;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if user has permission. Returns null if the data is not materialized (caller falls back).
     * Returns true/false if the check succeeded.
     */
    public function check(int|string $userId, string $permissionKey, PermissionTypeEnum $type, $teamIds): ?bool
    {
        if (!$this->isSupported()) {
            return null;
        }

        if (!$this->ensureReady((int) $userId, $teamIds)) {
            return null;
        }

        $version = $this->versions->get($userId);

        try {
            $redis = $this->redis();

            if ($redis->sismember(CacheKeyBuilder::userDenySet($userId, $version, $teamIds), $permissionKey)) {
                return false;
            }

            return (bool) $redis->sismember(
                CacheKeyBuilder::userAllowSet($userId, $version, $teamIds),
                $permissionKey . '|' . $type->value,
            );
        } catch (\Throwable $e) {
            \Log::warning('UserPermissionSet::check failed, falling back: ' . $e->getMessage(), [
                'user_id' => $userId,
                'permission_key' => $permissionKey,
            ]);
            return null;
        }
    }

    /**
     * Materialize the two sets from a permission array (output of getUserPermissionsOptimized).
     * Idempotent: overwrites the keys.
     */
    public function materialize(int|string $userId, array $permissions, $teamIds, ?int $ttl = null): void
    {
        if (!$this->isSupported()) {
            return;
        }

        $version = $this->versions->get($userId);
        $ttl = $ttl ?? (int) config('kompo-auth.cache.ttl', 900);

        $allowKey = CacheKeyBuilder::userAllowSet($userId, $version, $teamIds);
        $denyKey = CacheKeyBuilder::userDenySet($userId, $version, $teamIds);
        $readyKey = $this->readyKey($userId, $version, $teamIds);

        $allowMembers = [];
        $denyMembers = [];

        foreach ($permissions as $complex) {
            $key = getPermissionKey($complex);
            $type = getPermissionType($complex);

            if (!$key || !$type) {
                continue;
            }

            if ($type === PermissionTypeEnum::DENY) {
                $denyMembers[$key] = true;
                continue;
            }

            foreach (PermissionTypeEnum::cases() as $candidate) {
                if ($candidate === PermissionTypeEnum::DENY) {
                    continue;
                }
                if (PermissionTypeEnum::hasPermission($type, $candidate)) {
                    $allowMembers[$key . '|' . $candidate->value] = true;
                }
            }
        }

        try {
            $redis = $this->redis();
            $redis->del($allowKey, $denyKey, $readyKey);

            if ($allowMembers) {
                $redis->sadd($allowKey, ...array_keys($allowMembers));
                $redis->expire($allowKey, $ttl);
            }

            if ($denyMembers) {
                $redis->sadd($denyKey, ...array_keys($denyMembers));
                $redis->expire($denyKey, $ttl);
            }

            // Set ready sentinel with same TTL so it expires in sync.
            $redis->setex($readyKey, $ttl, '1');

            $this->readyRequestCache[$this->cacheSlot($userId, $version, $teamIds)] = true;
        } catch (\Throwable $e) {
            \Log::warning('UserPermissionSet::materialize failed: ' . $e->getMessage(), [
                'user_id' => $userId,
            ]);
        }
    }

    public function flushRequestCache(): void
    {
        $this->readyRequestCache = [];
    }

    /**
     * Returns true if the sets are ready to be queried. Consults a per-request cache,
     * then Redis EXISTS on the sentinel key. Does NOT hydrate; caller handles miss.
     */
    private function ensureReady(int $userId, $teamIds): bool
    {
        $version = $this->versions->get($userId);
        $slot = $this->cacheSlot($userId, $version, $teamIds);

        if (array_key_exists($slot, $this->readyRequestCache)) {
            return $this->readyRequestCache[$slot];
        }

        try {
            $redis = $this->redis();
            $exists = (bool) $redis->exists($this->readyKey($userId, $version, $teamIds));
            $this->readyRequestCache[$slot] = $exists;
            return $exists;
        } catch (\Throwable) {
            $this->readyRequestCache[$slot] = false;
            return false;
        }
    }

    private function readyKey(int|string $userId, int $version, $teamIds): string
    {
        return 'user_permset_ready.' . $userId . '.v' . $version . '.' . $this->teamKey($teamIds);
    }

    private function teamKey($teamIds): string
    {
        if ($teamIds === null) {
            return 'all';
        }
        if (is_iterable($teamIds)) {
            return md5(json_encode(collect($teamIds)->sort()->values()));
        }
        return (string) $teamIds;
    }

    private function cacheSlot(int|string $userId, int $version, $teamIds): string
    {
        return $userId . '.' . $version . '.' . $this->teamKey($teamIds);
    }

    private function redis()
    {
        /** @var RedisStore $store */
        $store = Cache::getStore();
        return $store->connection();
    }
}
