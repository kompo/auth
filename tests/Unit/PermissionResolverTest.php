<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Teams\PermissionResolver;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Permission Resolver Test
 * 
 * Tests the PermissionResolver service - core of authorization system.
 * 
 * Scenarios covered:
 * - userHasPermission method
 * - getTeamsWithPermissionForUser method
 * - getAllAccessibleTeamsForUser method
 * - getTeamsQueryWithPermissionForUser method
 * - Cache optimization
 * - Request-level cache
 * - DENY checking precedence
 * - Team hierarchy integration
 */
class PermissionResolverTest extends TestCase
{
    protected PermissionResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(PermissionResolver::class);

        AuthTestHelpers::createPermission('TestResource');
    }

    /**
     * INVARIANT: PermissionResolver correctly resolves permissions
     * 
     * @test
     */
    public function test_permission_resolver_resolves_permissions_correctly()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Use resolver directly
        $hasPermission = $this->resolver->userHasPermission(
            $user->id,
            'TestResource',
            PermissionTypeEnum::READ
        );

        // Assert
        $this->assertTrue($hasPermission, 'Resolver should correctly resolve READ permission');
    }

    /**
     * INVARIANT: Resolver checks DENY first
     * 
     * @test
     */
    public function test_resolver_checks_deny_first()
    {
        // Arrange: User with ALLOW and DENY
        $scenario = AuthTestHelpers::createDeniedScenario();
        $user = $scenario['user'];

        // Act: Use resolver
        $hasPermission = $this->resolver->userHasPermission(
            $user->id,
            'TestResource',
            PermissionTypeEnum::READ
        );

        // Assert: DENY should block
        $this->assertFalse($hasPermission, 'Resolver should check DENY first and block access');
    }

    /**
     * INVARIANT: getTeamsWithPermissionForUser returns correct teams
     * 
     * @test
     */
    public function test_get_teams_with_permission_for_user()
    {
        // Arrange: User with permission in Team A only
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $role = AuthTestHelpers::createRole('Team A Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $role, $teamA);

        // Act: Get teams with permission
        $teamsWithPermission = $this->resolver->getTeamsWithPermissionForUser(
            $user->id,
            'TestResource',
            PermissionTypeEnum::READ
        );

        // Assert: Should contain Team A only
        $this->assertContains($teamA->id, $teamsWithPermission);
        $this->assertNotContains($teamB->id, $teamsWithPermission);
    }

    /**
     * INVARIANT: Resolver uses request-level cache
     * 
     * @test
     */
    public function test_resolver_uses_request_cache()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: First call
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $result1 = $this->resolver->userHasPermission($user->id, 'TestResource', PermissionTypeEnum::READ);
        $queries1 = $this->getQueryCount();

        // Second call (should use request cache)
        \DB::flushQueryLog();
        $result2 = $this->resolver->userHasPermission($user->id, 'TestResource', PermissionTypeEnum::READ);
        $queries2 = $this->getQueryCount();

        // Assert: Second call uses cache
        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertEquals(0, $queries2, 'Second call should use request-level cache');
    }

    /**
     * INVARIANT: Resolver respects team context
     * 
     * @test
     */
    public function test_resolver_respects_team_context()
    {
        // Arrange: User with permission in Team A
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $role = AuthTestHelpers::createRole('Team A Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $role, $teamA);

        // Act: Check with different team contexts
        $hasInTeamA = $this->resolver->userHasPermission($user->id, 'TestResource', PermissionTypeEnum::READ, $teamA->id);
        $hasInTeamB = $this->resolver->userHasPermission($user->id, 'TestResource', PermissionTypeEnum::READ, $teamB->id);

        // Assert
        $this->assertTrue($hasInTeamA, 'Should have permission in Team A');
        $this->assertFalse($hasInTeamB, 'Should NOT have permission in Team B');
    }

    /**
     * INVARIANT: getTeamsQueryWithPermissionForUser returns valid query
     * 
     * @test
     */
    public function test_get_teams_query_with_permission_returns_query()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Act: Get teams query
        $query = $this->resolver->getTeamsQueryWithPermissionForUser(
            $user->id,
            'TestResource',
            PermissionTypeEnum::READ
        );

        // Assert: Should be a query builder
        $this->assertInstanceOf(
            \Illuminate\Database\Query\Builder::class,
            $query
        );

        // Execute query
        $results = $query->get();
        
        // Should include team with permission
        $this->assertGreaterThan(0, $results->count());
    }

    /**
     * Performance: Resolver optimizes batch queries
     * 
     * @test
     */
    public function test_resolver_batch_optimization()
    {
        // Arrange: User with multiple team roles
        $rolesConfig = [
            ['roleName' => 'Role 1', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
            ['roleName' => 'Role 2', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
            ['roleName' => 'Role 3', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
        ];

        $data = AuthTestHelpers::createUserWithMultipleRoles($rolesConfig);
        $user = $data['user'];

        // Act: Check permission (should batch process roles)
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $hasPermission = $this->resolver->userHasPermission(
            $user->id,
            'TestResource',
            PermissionTypeEnum::READ
        );

        $queryCount = $this->getQueryCount();

        // Assert: Should be optimized (batch queries)
        $this->assertTrue($hasPermission);
        $this->assertLessThanOrEqual(
            10,
            $queryCount,
            "Resolver should batch-optimize queries (got {$queryCount})"
        );
    }

    /**
     * INVARIANT: Resolver clearUserCache invalidates correctly
     * 
     * @test
     */
    public function test_resolver_clear_user_cache()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Populate cache
        $this->resolver->userHasPermission($user->id, 'TestResource', PermissionTypeEnum::READ);

        // Act: Clear cache
        $this->resolver->clearUserCache($user->id);

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Check again (should query DB)
        $this->resolver->userHasPermission($user->id, 'TestResource', PermissionTypeEnum::READ);
        $queries = $this->getQueryCount();

        // Assert: Cache was cleared (queries > 0)
        $this->assertGreaterThan(0, $queries, 'Cache should be cleared');
    }

    /**
     * Edge case: Resolver with no team roles
     * 
     * @test
     */
    public function test_resolver_with_no_team_roles()
    {
        // Arrange: User without any team roles
        $user = UserFactory::new()->create();

        // Act: Check permission
        $hasPermission = $this->resolver->userHasPermission(
            $user->id,
            'TestResource',
            PermissionTypeEnum::READ
        );

        // Assert: Should return false (no roles = no permissions)
        $this->assertFalse($hasPermission, 'User without team roles should have no permissions');
    }
}

