<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * PermissionTeamRole Model Test
 * 
 * Tests PermissionTeamRole model (direct permissions on team_role).
 * 
 * Scenarios covered:
 * - Direct permission on team role overrides role permission
 * - scopeForTeamRole()
 * - scopeForPermission()
 * - scopeValid() / scopeDenied()
 * - Cache invalidation on save/delete
 */
class PermissionTeamRoleTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('TestResource');
    }

    /**
     * INVARIANT: Direct permission on team role overrides role permission
     * 
     * @test
     */
    public function test_direct_permission_overrides_role_permission()
    {
        // Arrange: Role with READ permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $teamRole = $data['teamRole'];

        // Initially has READ only
        $this->assertTrue($user->hasPermission('TestResource', PermissionTypeEnum::READ));
        $this->assertFalse($user->hasPermission('TestResource', PermissionTypeEnum::WRITE));

        // Act: Add direct ALL permission to team role
        AuthTestHelpers::addDirectPermissionToTeamRole(
            $teamRole,
            'TestResource',
            PermissionTypeEnum::ALL
        );

        $this->clearPermissionCache();
        $user->clearPermissionCache();

        // Assert: Now should have ALL (direct overrides role READ)
        $this->assertTrue($user->hasPermission('TestResource', PermissionTypeEnum::ALL));
    }

    /**
     * INVARIANT: Direct DENY overrides role ALLOW
     * 
     * @test
     */
    public function test_direct_deny_overrides_role_allow()
    {
        // Arrange: Role with ALL permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $teamRole = $data['teamRole'];

        // Initially has ALL
        $this->assertTrue($user->hasPermission('TestResource', PermissionTypeEnum::ALL));

        // Act: Add direct DENY to team role
        AuthTestHelpers::addDirectPermissionToTeamRole(
            $teamRole,
            'TestResource',
            PermissionTypeEnum::DENY
        );

        $this->clearPermissionCache();
        $user->clearPermissionCache();

        // Assert: Now should be DENIED
        $this->assertFalse(
            $user->hasPermission('TestResource', PermissionTypeEnum::READ),
            'Direct DENY should override role ALL'
        );
    }

    /**
     * INVARIANT: scopeForTeamRole() filters correctly
     * 
     * @test
     */
    public function test_scope_for_team_role()
    {
        // Arrange: Create direct permissions for different team roles
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

        $teamRole1 = $data1['teamRole'];
        $teamRole2 = $data2['teamRole'];

        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole1, 'TestResource', PermissionTypeEnum::WRITE);
        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole2, 'TestResource', PermissionTypeEnum::DENY);

        // Act: Query for specific team role
        $perms = PermissionTeamRole::forTeamRole($teamRole1->id)->get();

        // Assert: Should only return team role 1's permissions
        $this->assertGreaterThan(0, $perms->count());
        $perms->each(function($perm) use ($teamRole1) {
            $this->assertEquals($teamRole1->id, $perm->team_role_id);
        });
    }

    /**
     * INVARIANT: scopeForPermission() filters correctly
     * 
     * @test
     */
    public function test_scope_for_permission()
    {
        // Arrange: Multiple direct permissions
        $permission1 = AuthTestHelpers::createPermission('Permission1');
        $permission2 = AuthTestHelpers::createPermission('Permission2');

        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole, 'Permission1', PermissionTypeEnum::READ);
        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole, 'Permission2', PermissionTypeEnum::WRITE);

        // Act: Query for specific permission
        $permsForPermission1 = PermissionTeamRole::forPermission($permission1->id)->get();

        // Assert: Should only return Permission1 assignments
        $this->assertGreaterThan(0, $permsForPermission1->count());
        $permsForPermission1->each(function($perm) use ($permission1) {
            $this->assertEquals($permission1->id, $perm->permission_id);
        });
    }

    /**
     * INVARIANT: scopeValid() filters out DENY
     * 
     * @test
     */
    public function test_scope_valid_filters_deny()
    {
        // Arrange: Mix of ALLOW and DENY direct permissions
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole, 'AllowedPerm', PermissionTypeEnum::READ);
        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole, 'DeniedPerm', PermissionTypeEnum::DENY);

        AuthTestHelpers::createPermission('AllowedPerm');
        AuthTestHelpers::createPermission('DeniedPerm');

        // Act: Query valid only
        $validPerms = PermissionTeamRole::valid()->get();

        // Assert: Should not include DENY
        $validPerms->each(function($perm) {
            $this->assertNotEquals(
                PermissionTypeEnum::DENY->value,
                $perm->permission_type,
                'Valid scope should filter out DENY'
            );
        });
    }

    /**
     * INVARIANT: scopeDenied() returns only DENY
     * 
     * @test
     */
    public function test_scope_denied_returns_deny_only()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole, 'AllowedPerm', PermissionTypeEnum::ALL);
        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole, 'DeniedPerm', PermissionTypeEnum::DENY);

        AuthTestHelpers::createPermission('AllowedPerm');
        AuthTestHelpers::createPermission('DeniedPerm');

        // Act: Query denied only
        $deniedPerms = PermissionTeamRole::denied()->get();

        // Assert: Should only include DENY
        $deniedPerms->each(function($perm) {
            $this->assertEquals(
                PermissionTypeEnum::DENY->value,
                $perm->permission_type,
                'Denied scope should only return DENY'
            );
        });
    }

    /**
     * INVARIANT: PermissionTeamRole clearCache on save
     * 
     * @test
     */
    public function test_permission_team_role_clears_cache_on_save()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $teamRole = $data['teamRole'];

        // Populate cache
        $user->hasPermission('TestResource', PermissionTypeEnum::READ);

        // Act: Add direct permission (triggers save)
        AuthTestHelpers::addDirectPermissionToTeamRole($teamRole, 'NewPerm', PermissionTypeEnum::ALL);

        AuthTestHelpers::createPermission('NewPerm');

        $user = $user->fresh();

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Check permission (should query - cache invalidated)
        $user->hasPermission('NewPerm', PermissionTypeEnum::ALL);
        $queries = $this->getQueryCount();

        // Assert: Cache was invalidated
        $this->assertGreaterThan(0, $queries, 'PermissionTeamRole save should invalidate cache');
    }
}


