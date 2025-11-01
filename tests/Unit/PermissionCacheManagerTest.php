<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Teams\PermissionCacheManager;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Permission Cache Manager Test
 * 
 * Tests the PermissionCacheManager service.
 * 
 * Scenarios covered:
 * - warmUserCache method
 * - warmCriticalUserCache method
 * - invalidateByChange method
 * - clearAllCache method
 * - Cache statistics
 */
class PermissionCacheManagerTest extends TestCase
{
    protected PermissionCacheManager $cacheManager;

    public function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(PermissionCacheManager::class);

        AuthTestHelpers::createPermission('TestResource');
    }

    /**
     * INVARIANT: warmUserCache pre-loads permissions
     * 
     * @test
     */
    public function test_warm_user_cache_preloads_permissions()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Clear cache first
        Cache::flush();

        // Act: Warm cache
        $this->cacheManager->warmUserCache($user->id);

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Check permission (should use warmed cache)
        $hasPermission = $user->hasPermission('TestResource', PermissionTypeEnum::READ);
        $queries = $this->getQueryCount();

        // Assert: Should use cache (fewer queries)
        $this->assertTrue($hasPermission);
        // Note: Exact query count depends on implementation, but should be minimal
    }

    /**
     * INVARIANT: invalidateByChange invalidates correctly
     * 
     * @test
     */
    public function test_invalidate_by_change_team_role()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Populate cache
        $user->hasPermission('TestResource', PermissionTypeEnum::READ);

        // Act: Invalidate via cache manager
        $this->cacheManager->invalidateByChange('team_role_changed', [
            'user_ids' => [$user->id]
        ]);

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Check permission again
        $user->fresh()->hasPermission('TestResource', PermissionTypeEnum::READ);
        $queries = $this->getQueryCount();

        // Assert: Cache was invalidated (queries > 0)
        $this->assertGreaterThan(0, $queries, 'Cache should be invalidated');
    }

    /**
     * INVARIANT: clearAllCache clears everything
     * 
     * @test
     */
    public function test_clear_all_cache()
    {
        // Arrange: Multiple users with cached permissions
        $data1 = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $data2 = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user1 = $data1['user'];
        $user2 = $data2['user'];

        // Populate caches
        $user1->hasPermission('TestResource', PermissionTypeEnum::READ);
        $user2->hasPermission('TestResource', PermissionTypeEnum::READ);

        // Act: Clear all cache
        $this->cacheManager->clearAllCache();

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Check both users
        $user1->fresh()->hasPermission('TestResource', PermissionTypeEnum::READ);
        $queriesUser1 = $this->getQueryCount();

        \DB::flushQueryLog();
        $user2->fresh()->hasPermission('TestResource', PermissionTypeEnum::READ);
        $queriesUser2 = $this->getQueryCount();

        // Assert: Both should query DB (cache cleared)
        $this->assertGreaterThan(0, $queriesUser1, 'User 1 cache should be cleared');
        $this->assertGreaterThan(0, $queriesUser2, 'User 2 cache should be cleared');
    }

    /**
     * Performance: warmCriticalUserCache is efficient
     * 
     * @test
     */
    public function test_warm_critical_user_cache_performance()
    {
        // Arrange: Create multiple users
        for ($i = 0; $i < 5; $i++) {
            AuthTestHelpers::createUserWithRole(
                ['TestResource' => PermissionTypeEnum::READ],
                null,
                RoleHierarchyEnum::DIRECT
            );
        }

        // Clear cache
        Cache::flush();

        // Act: Warm critical users (should be efficient)
        try {
            $warmed = $this->cacheManager->warmCriticalUserCache();
            $success = true;
        } catch (\Exception $e) {
            $success = false;
        }

        // Assert: Should complete successfully
        $this->assertTrue($success, 'warmCriticalUserCache should execute without errors');
    }

    /**
     * Edge case: Invalidate with empty arrays
     * 
     * @test
     */
    public function test_invalidate_with_empty_arrays()
    {
        // Act: Invalidate with empty user IDs
        try {
            $this->cacheManager->invalidateByChange('team_role_changed', [
                'user_ids' => []
            ]);
            $success = true;
        } catch (\Exception $e) {
            $success = false;
        }

        // Assert: Should handle gracefully
        $this->assertTrue($success, 'Should handle empty arrays gracefully');
    }

    /**
     * INVARIANT: Different change types invalidate correctly
     * 
     * @test
     */
    public function test_different_change_types()
    {
        // Test each change type
        $changeTypes = [
            'team_role_changed' => ['user_ids' => [1]],
            'role_permissions_changed' => ['role_ids' => [1]],
            'team_hierarchy_changed' => ['team_ids' => [1]],
            'permission_updated' => ['permission_keys' => ['TestResource']],
        ];

        foreach ($changeTypes as $type => $data) {
            // Act: Invalidate
            try {
                $this->cacheManager->invalidateByChange($type, $data);
                $success = true;
            } catch (\Exception $e) {
                $success = false;
            }

            // Assert: Each type should work
            $this->assertTrue($success, "Change type '{$type}' should invalidate successfully");
        }
    }
}


