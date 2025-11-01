<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AssertionHelpers;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Cache Authorization Test
 * 
 * Tests the caching system for authorization checks.
 * 
 * Scenarios covered:
 * D) Cache is used on subsequent permission checks (reduced queries)
 * D) Cache is invalidated when roles/permissions change
 * D) TTL expiration causes cache refresh
 * D) Cache tags allow efficient invalidation
 */
class CacheAuthorizationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('CachedResource');
    }

    /**
     * INVARIANT: Second permission check uses cache (fewer queries)
     * 
     * @test
     */
    public function test_second_permission_check_uses_cache()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['CachedResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: First check (no cache)
        $this->enableQueryLog();
        DB::flushQueryLog();
        
        $hasPermissionFirst = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $firstCheckQueries = $this->getQueryCount();

        // Second check (should use cache)
        DB::flushQueryLog();
        $hasPermissionSecond = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $secondCheckQueries = $this->getQueryCount();

        // Assert: Both checks return true
        $this->assertTrue($hasPermissionFirst, 'First check should return true');
        $this->assertTrue($hasPermissionSecond, 'Second check should return true');

        // Assert: Second check should have significantly fewer queries (using cache)
        $this->assertLessThan(
            $firstCheckQueries,
            $secondCheckQueries,
            "Second check should use cache (queries: first={$firstCheckQueries}, second={$secondCheckQueries})"
        );

        // Ideally, second check should have 0 queries if fully cached
        $this->assertEquals(
            0,
            $secondCheckQueries,
            'Second check should hit cache and have 0 queries'
        );
    }

    /**
     * INVARIANT: Cache is invalidated when user roles change
     * 
     * @test
     */
    public function test_cache_invalidated_when_roles_change()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['CachedResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // First check (populate cache)
        $hasPermissionBefore = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $this->assertTrue($hasPermissionBefore);

        // Act: Change user's roles (add a new role with DENY)
        $denyRole = AuthTestHelpers::createRole('Deny Role', [
            'CachedResource' => PermissionTypeEnum::DENY,
        ]);

        $newTeamRole = AuthTestHelpers::assignRoleToUser($user, $denyRole, $team);

        // Cache should be invalidated automatically when TeamRole is saved
        // Force refresh user from DB
        $user = $user->fresh();

        // Act: Check permission again (should reflect new DENY)
        $hasPermissionAfter = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);

        // Assert: Permission should now be DENIED
        $this->assertFalse(
            $hasPermissionAfter,
            'Cache should be invalidated and permission should reflect new DENY'
        );
    }

    /**
     * INVARIANT: Manual cache clear forces fresh permission resolution
     * 
     * @test
     */
    public function test_manual_cache_clear_forces_fresh_resolution()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['CachedResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Populate cache
        $hasPermission = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $this->assertTrue($hasPermission);

        // Act: Manually clear cache
        $user->clearPermissionCache();

        // Enable query log to verify queries happen again
        $this->enableQueryLog();
        DB::flushQueryLog();

        $hasPermissionAfterClear = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $queriesAfterClear = $this->getQueryCount();

        // Assert: Permission still true, but queries were executed (cache was bypassed)
        $this->assertTrue($hasPermissionAfterClear);
        $this->assertGreaterThan(
            0,
            $queriesAfterClear,
            'After cache clear, queries should be executed again'
        );
    }

    /**
     * INVARIANT: Cache tags allow bulk invalidation
     * 
     * @test
     */
    public function test_cache_tags_allow_bulk_invalidation()
    {
        // Arrange: Multiple users with permissions
        $data1 = AuthTestHelpers::createUserWithRole(
            ['CachedResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $data2 = AuthTestHelpers::createUserWithRole(
            ['CachedResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user1 = $data1['user'];
        $user2 = $data2['user'];

        // Populate caches
        $user1->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $user2->hasPermission('CachedResource', PermissionTypeEnum::READ);

        // Act: Flush all permission caches using tags
        Cache::tags(['permissions-v2'])->flush();

        // Enable query log
        $this->enableQueryLog();
        DB::flushQueryLog();

        // Check permissions again
        $user1->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $queriesUser1 = $this->getQueryCount();

        DB::flushQueryLog();
        $user2->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $queriesUser2 = $this->getQueryCount();

        // Assert: Both users should have queries (cache was flushed)
        $this->assertGreaterThan(0, $queriesUser1, 'User 1 should have queries after tag flush');
        $this->assertGreaterThan(0, $queriesUser2, 'User 2 should have queries after tag flush');
    }

    /**
     * Performance: Cache significantly reduces database load
     * 
     * @test
     */
    public function test_cache_reduces_database_load_significantly()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['CachedResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Perform 10 permission checks
        $this->enableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 10; $i++) {
            $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        }

        $totalQueries = $this->getQueryCount();

        // Assert: Total queries should be much less than 10 * (queries per check)
        // If each check without cache takes ~5 queries, 10 checks would be 50 queries
        // With cache, should be ~5 queries (first check) + 0 for the rest = 5 total
        $this->assertLessThanOrEqual(
            15,
            $totalQueries,
            "Cache should significantly reduce queries (got {$totalQueries} for 10 checks)"
        );
    }

    /**
     * INVARIANT: Cache respects team context
     * 
     * @test
     */
    public function test_cache_respects_team_context()
    {
        // Arrange: User with permission in Team A, not in Team B
        $user = \Kompo\Auth\Database\Factories\UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $roleA = AuthTestHelpers::createRole('Role A', [
            'CachedResource' => PermissionTypeEnum::READ,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $roleA, $teamA);

        // Act: Check permission in both teams (should cache separately)
        $hasPermissionTeamA = $user->hasPermission('CachedResource', PermissionTypeEnum::READ, $teamA->id);
        $hasPermissionTeamB = $user->hasPermission('CachedResource', PermissionTypeEnum::READ, $teamB->id);

        // Assert: Different results for different teams
        $this->assertTrue($hasPermissionTeamA, 'Should have permission in Team A');
        $this->assertFalse($hasPermissionTeamB, 'Should NOT have permission in Team B');

        // Act: Check again (should use cache for both)
        $this->enableQueryLog();
        DB::flushQueryLog();

        $hasPermissionTeamA2 = $user->hasPermission('CachedResource', PermissionTypeEnum::READ, $teamA->id);
        $hasPermissionTeamB2 = $user->hasPermission('CachedResource', PermissionTypeEnum::READ, $teamB->id);
        
        $queries = $this->getQueryCount();

        // Assert: Results still correct and cached
        $this->assertTrue($hasPermissionTeamA2);
        $this->assertFalse($hasPermissionTeamB2);
        $this->assertEquals(0, $queries, 'Second checks should use cache (0 queries)');
    }

    /**
     * Edge case: Cache handles null team context correctly
     * 
     * @test
     */
    public function test_cache_handles_null_team_context()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['CachedResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Check with null team (global check)
        $hasPermission1 = $user->hasPermission('CachedResource', PermissionTypeEnum::READ, null);
        
        $this->enableQueryLog();
        DB::flushQueryLog();
        
        $hasPermission2 = $user->hasPermission('CachedResource', PermissionTypeEnum::READ, null);
        $queries = $this->getQueryCount();

        // Assert: Should use cache
        $this->assertTrue($hasPermission1);
        $this->assertTrue($hasPermission2);
        $this->assertEquals(0, $queries, 'Global permission check should use cache');
    }

    /**
     * INVARIANT: Cache invalidation is automatic on TeamRole save
     * 
     * @test
     */
    public function test_cache_invalidation_automatic_on_team_role_save()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['CachedResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $teamRole = $data['teamRole'];

        // Populate cache
        $hasPermission = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $this->assertTrue($hasPermission);

        // Act: Update team role (trigger save event)
        $teamRole->role_hierarchy = RoleHierarchyEnum::DIRECT_AND_BELOW;
        $teamRole->save();

        // Refresh user
        $user = $user->fresh();

        // Enable query log
        $this->enableQueryLog();
        DB::flushQueryLog();

        $hasPermissionAfter = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $queries = $this->getQueryCount();

        // Assert: Cache should have been invalidated (queries > 0)
        $this->assertTrue($hasPermissionAfter);
        $this->assertGreaterThan(
            0,
            $queries,
            'Cache should be invalidated automatically when TeamRole is saved'
        );
    }

    /**
     * INVARIANT: Cache invalidation is automatic on TeamRole delete
     * 
     * @test
     */
    public function test_cache_invalidation_automatic_on_team_role_delete()
    {
        // Arrange: User with 2 roles
        $rolesConfig = [
            [
                'roleName' => 'Role 1',
                'permissions' => ['CachedResource' => PermissionTypeEnum::READ],
            ],
            [
                'roleName' => 'Role 2',
                'permissions' => ['CachedResource' => PermissionTypeEnum::ALL],
            ],
        ];

        $data = AuthTestHelpers::createUserWithMultipleRoles($rolesConfig);
        $user = $data['user'];
        $teamRoleToDelete = $data['teamRoles'][1];

        // Populate cache
        $hasPermission = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $this->assertTrue($hasPermission);

        // Act: Delete a team role
        $teamRoleToDelete->delete();

        // Refresh user
        $user = $user->fresh();

        // Enable query log
        $this->enableQueryLog();
        DB::flushQueryLog();

        $hasPermissionAfter = $user->hasPermission('CachedResource', PermissionTypeEnum::READ);
        $queries = $this->getQueryCount();

        // Assert: Cache should be invalidated (queries > 0)
        $this->assertTrue($hasPermissionAfter, 'Should still have permission from Role 1');
        $this->assertGreaterThan(
            0,
            $queries,
            'Cache should be invalidated automatically when TeamRole is deleted'
        );
    }
}


