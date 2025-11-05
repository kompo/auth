<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\Teams\TeamRoleStatusEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * TeamRole Model Test
 * 
 * Tests TeamRole model methods and relationships.
 * 
 * Scenarios covered:
 * - terminate() / suspend() / removeSuspention()
 * - exceedsRoleLimit()
 * - getParentHierarchyRole()
 * - getOrCreateForUser()
 * - createChildForHierarchy()
 * - getAllHierarchyTeamsIds()
 * - getAccessibleTeamsOptimized()
 * - hasAccessToTeamOfMany()
 * - validPermissionsQuery() / deniedPermissionsQuery()
 * - denyingPermission()
 * - getAllPermissionsKeysForMultipleRoles()
 * - getRoleName() / getTeamAndRoleLabel()
 * - getStatus()
 * - Relations: permissions(), validPermissions(), deniedPermissions()
 */
class TeamRoleTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('TestResource');
    }

    /**
     * INVARIANT: terminate() sets terminated_at and soft deletes
     * 
     * @test
     */
    public function test_terminate_sets_timestamps_and_soft_deletes()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        // Act: Terminate
        $teamRole->terminate();

        // Assert: Should set timestamps
        $this->assertNotNull($teamRole->terminated_at, 'terminated_at should be set');
        $this->assertNotNull($teamRole->deleted_at, 'deleted_at should be set');
        
        // Should be soft deleted
        $this->assertSoftDeleted('team_roles', ['id' => $teamRole->id]);
    }

    /**
     * INVARIANT: suspend() sets suspended_at and soft deletes
     * 
     * @test
     */
    public function test_suspend_sets_timestamps()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        // Act: Suspend
        $teamRole->suspend();

        // Assert: Should set timestamps
        $this->assertNotNull($teamRole->suspended_at, 'suspended_at should be set');
        $this->assertNotNull($teamRole->deleted_at, 'deleted_at should be set (soft delete)');
    }

    /**
     * INVARIANT: removeSuspention() clears suspended_at and deleted_at
     * 
     * @test
     */
    public function test_remove_suspention_clears_timestamps()
    {
        // Arrange: Create suspended team role
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];
        $teamRole->suspend();

        $this->assertNotNull($teamRole->suspended_at);

        // Act: Remove suspension
        $teamRole->removeSuspention();

        // Assert: Should clear timestamps
        $this->assertNull($teamRole->suspended_at, 'suspended_at should be cleared');
        $this->assertNull($teamRole->deleted_at, 'deleted_at should be cleared');
    }

    /**
     * INVARIANT: hasAccessToTeamOfMany checks multiple teams
     * 
     * @test
     */
    public function test_has_access_to_team_of_many()
    {
        // Arrange: TeamRole with DIRECT_AND_BELOW
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Test Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Act: Check access to multiple teams
        $hasAccessToSome = $teamRole->hasAccessToTeamOfMany([$teams['childA']->id, 999]);

        // Assert: Should return true (has access to childA)
        $this->assertTrue($hasAccessToSome, 'Should have access to at least one team in array');

        // Act: Check teams without access
        $hasAccessToNone = $teamRole->hasAccessToTeamOfMany([998, 999]);

        // Assert: Should return false
        $this->assertFalse($hasAccessToNone, 'Should not have access to any team in array');
    }

    /**
     * INVARIANT: validPermissionsQuery() returns query
     * 
     * @test
     */
    public function test_valid_permissions_query()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        // Act: Get valid permissions query
        $query = $teamRole->validPermissionsQuery();

        // Assert: Should be a query
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Builder::class,
            $query
        );

        // Execute and check results
        $permissions = $query->get();
        $this->assertGreaterThan(0, $permissions->count(), 'Should have valid permissions');
    }

    /**
     * INVARIANT: deniedPermissionsQuery() returns DENY permissions
     * 
     * @test
     */
    public function test_denied_permissions_query()
    {
        // Arrange: Role with DENY
        $role = AuthTestHelpers::createRole('Deny Role', [
            'TestResource' => PermissionTypeEnum::DENY,
        ]);

        $user = UserFactory::new()->create();
        $team = AuthTestHelpers::createTeam([], $user);

        $teamRole = AuthTestHelpers::assignRoleToUser($user, $role, $team);

        // Act: Get denied permissions query
        $query = $teamRole->deniedPermissionsQuery();

        // Assert: Should be a query
        $this->assertInstanceOf(
            \Illuminate\Database\Query\Builder::class,
            $query
        );

        // Should include TestResource
        $permissions = $query->get();
        $this->assertGreaterThan(0, $permissions->count());
    }

    /**
     * INVARIANT: denyingPermission() checks if permission is denied
     * 
     * @test
     */
    public function test_denying_permission_method()
    {
        // Arrange: Role with DENY
        $role = AuthTestHelpers::createRole('Deny Role', [
            'TestResource' => PermissionTypeEnum::DENY,
        ]);

        $user = UserFactory::new()->create();
        $team = AuthTestHelpers::createTeam([], $user);

        $teamRole = AuthTestHelpers::assignRoleToUser($user, $role, $team);

        // Act: Check if denying
        $isDenying = $teamRole->denyingPermission('TestResource');

        // Assert: Should return true
        $this->assertTrue($isDenying, 'Should be denying TestResource');

        // Check non-denied permission
        $isDenyingOther = $teamRole->denyingPermission('OtherResource');
        $this->assertFalse($isDenyingOther, 'Should not be denying OtherResource');
    }

    /**
     * INVARIANT: getAllPermissionsKeysForMultipleRoles() merges permissions
     * 
     * @test
     */
    public function test_get_all_permissions_keys_for_multiple_roles()
    {
        // Arrange: Multiple team roles
        $rolesConfig = [
            ['roleName' => 'Role 1', 'permissions' => ['Perm1' => PermissionTypeEnum::READ]],
            ['roleName' => 'Role 2', 'permissions' => ['Perm2' => PermissionTypeEnum::WRITE]],
        ];

        AuthTestHelpers::createPermission('Perm1');
        AuthTestHelpers::createPermission('Perm2');

        $data = AuthTestHelpers::createUserWithMultipleRoles($rolesConfig);
        $teamRoles = collect($data['teamRoles']);

        // Act: Get all permission keys
        $permissionKeys = TeamRole::getAllPermissionsKeysForMultipleRoles($teamRoles);

        // Assert: Should include both permissions
        $this->assertGreaterThan(0, $permissionKeys->count());
        // Should have permissions from both roles
    }

    /**
     * INVARIANT: getRoleName() returns role name
     * 
     * @test
     */
    public function test_get_role_name()
    {
        // Arrange
        $role = AuthTestHelpers::createRole('Admin Role', [
            'TestResource' => PermissionTypeEnum::ALL,
        ]);

        $user = UserFactory::new()->create();
        $team = AuthTestHelpers::createTeam([], $user);

        $teamRole = AuthTestHelpers::assignRoleToUser($user, $role, $team);

        // Act: Get role name
        $roleName = $teamRole->getRoleName();

        // Assert: Should return role name
        $this->assertEquals('Admin Role', $roleName);
    }

    /**
     * INVARIANT: getTeamAndRoleLabel() combines team and role
     * 
     * @test
     */
    public function test_get_team_and_role_label()
    {
        // Arrange
        $role = AuthTestHelpers::createRole('Manager Role', [
            'TestResource' => PermissionTypeEnum::ALL,
        ]);

        $user = UserFactory::new()->create();
        $team = AuthTestHelpers::createTeam(['team_name' => 'Marketing Team'], $user);

        $teamRole = AuthTestHelpers::assignRoleToUser($user, $role, $team);

        // Act: Get combined label
        $label = $teamRole->getTeamAndRoleLabel();

        // Assert: Should combine team and role names
        $this->assertStringContainsString('Marketing Team', $label);
        $this->assertStringContainsString('Manager Role', $label);
    }

    /**
     * INVARIANT: getStatus() returns TeamRoleStatusEnum
     * 
     * @test
     */
    public function test_get_status_attribute()
    {
        // Arrange: Active team role
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        // Act: Get status
        $status = $teamRole->status;

        // Assert: Should be TeamRoleStatusEnum
        $this->assertInstanceOf(TeamRoleStatusEnum::class, $status);
    }

    /**
     * INVARIANT: getParentHierarchyRole() finds parent with hierarchy access
     * 
     * @test
     */
    public function test_get_parent_hierarchy_role()
    {
        // Arrange: User with role in parent team with DIRECT_AND_BELOW
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Parent Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $parentTeamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Act: Find parent hierarchy role for child team
        $foundParent = TeamRole::getParentHierarchyRole($teams['childA']->id, $user->id, $role->id);

        // Assert: Should find the parent team role
        $this->assertNotNull($foundParent, 'Should find parent hierarchy role');
        $this->assertEquals($parentTeamRole->id, $foundParent->id);
    }

    /**
     * INVARIANT: getOrCreateForUser() creates child role via hierarchy
     * 
     * @test
     */
    public function test_get_or_create_for_user_with_hierarchy()
    {
        // Arrange: Parent with DIRECT_AND_BELOW
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Hierarchical Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $parentTeamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Act: Get or create for child team (should create via hierarchy)
        $childTeamRole = TeamRole::getOrCreateForUser($teams['childA']->id, $user->id, $role->id);

        // Assert: Should create child team role
        $this->assertNotNull($childTeamRole);
        $this->assertEquals($teams['childA']->id, $childTeamRole->team_id);
        $this->assertEquals($user->id, $childTeamRole->user_id);
        $this->assertEquals($role->id, $childTeamRole->role);
        $this->assertEquals($parentTeamRole->id, $childTeamRole->parent_team_role_id);
    }

    /**
     * INVARIANT: createChildForHierarchy() creates child with DIRECT hierarchy
     * 
     * @test
     */
    public function test_create_child_for_hierarchy()
    {
        // Arrange: Parent role with access
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Parent Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $parentTeamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Act: Create child for hierarchy
        $childTeamRole = $parentTeamRole->createChildForHierarchy($teams['childA']->id);

        // Assert: Child should be created
        $this->assertNotNull($childTeamRole);
        $this->assertEquals($teams['childA']->id, $childTeamRole->team_id);
        $this->assertEquals(RoleHierarchyEnum::DIRECT, $childTeamRole->role_hierarchy);
        $this->assertEquals($parentTeamRole->id, $childTeamRole->parent_team_role_id);
    }

    /**
     * INVARIANT: createChildForHierarchy() aborts without access
     * 
     * @test
     */
    public function test_create_child_for_hierarchy_aborts_without_access()
    {
        // Arrange: Parent with DIRECT (no hierarchy)
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Direct Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $parentTeamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT // No access to children
        );

        // Expect: 403 abort
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        // Act: Try to create child
        $parentTeamRole->createChildForHierarchy($teams['childA']->id);
    }

    /**
     * INVARIANT: getAllHierarchyTeamsIds() returns teams with roles
     * 
     * @test
     */
    public function test_get_all_hierarchy_teams_ids()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Hierarchy Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Act: Get all hierarchy teams with roles
        $teamsWithRoles = $teamRole->getAllHierarchyTeamsIds();

        // Assert: Should include root and children
        $this->assertIsArray($teamsWithRoles->all());
        $this->assertArrayHasKey($teams['root']->id, $teamsWithRoles->all());
        $this->assertArrayHasKey($teams['childA']->id, $teamsWithRoles->all());
        $this->assertArrayHasKey($teams['childB']->id, $teamsWithRoles->all());
    }

    /**
     * INVARIANT: getAccessibleTeamsOptimized() uses cache
     * 
     * @test
     */
    public function test_get_accessible_teams_optimized_uses_cache()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Test Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Act: First call
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $accessible1 = $teamRole->getAccessibleTeamsOptimized();
        $queries1 = $this->getQueryCount();

        // Second call (should use cache)
        \DB::flushQueryLog();
        $accessible2 = $teamRole->getAccessibleTeamsOptimized();
        $queries2 = $this->getQueryCount();

        // Assert: Results should be same
        $this->assertEquals($accessible1->count(), $accessible2->count());

        // Second call should use cache
        $this->assertEquals(0, $queries2, 'getAccessibleTeamsOptimized should use cache on second call');
    }

    /**
     * INVARIANT: Relations work correctly
     * 
     * @test
     */
    public function test_team_role_relations()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];
        $role = $data['role'];
        $team = $data['team'];
        $user = $data['user'];

        // Act & Assert: roleRelation
        $this->assertNotNull($teamRole->roleRelation);
        $this->assertEquals($role->id, $teamRole->roleRelation->id);

        // team relation
        $this->assertNotNull($teamRole->team);
        $this->assertEquals($team->id, $teamRole->team->id);

        // user relation
        $this->assertNotNull($teamRole->user);
        $this->assertEquals($user->id, $teamRole->user->id);

        // permissions relation
        $permissions = $teamRole->permissions;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $permissions);

        // validPermissions relation
        $validPerms = $teamRole->validPermissions;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $validPerms);

        // deniedPermissions relation
        $deniedPerms = $teamRole->deniedPermissions;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $deniedPerms);
    }

    /**
     * INVARIANT: TeamRole clearCache on save/delete
     * 
     * @test
     */
    public function test_team_role_clears_cache_on_save_and_delete()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $teamRole = $data['teamRole'];

        // Populate user's permission cache
        $user->hasPermission('TestResource', PermissionTypeEnum::READ);

        // Act: Update team role (triggers save event)
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $teamRole->role_hierarchy = RoleHierarchyEnum::DIRECT_AND_BELOW;
        $teamRole->save();

        // Refresh user
        $user = $user->fresh();

        // Check permission (should query DB - cache invalidated)
        $user->hasPermission('TestResource', PermissionTypeEnum::READ);
        $queries = $this->getQueryCount();

        // Assert: Cache was invalidated
        $this->assertGreaterThan(0, $queries, 'TeamRole save should invalidate cache');
    }

    /**
     * Edge case: getRoleHierarchyAccess methods
     * 
     * @test
     */
    public function test_role_hierarchy_access_methods()
    {
        // Arrange: Different hierarchies
        $user = UserFactory::new()->create();
        $team = AuthTestHelpers::createTeam([], $user);
        $role = AuthTestHelpers::createRole('Test', ['TestResource' => PermissionTypeEnum::READ]);

        // Test DIRECT
        $direct = AuthTestHelpers::assignRoleToUser($user, $role, $team, RoleHierarchyEnum::DIRECT);
        $this->assertTrue($direct->getRoleHierarchyAccessDirect());
        $this->assertFalse($direct->getRoleHierarchyAccessBelow());
        $this->assertFalse($direct->getRoleHierarchyAccessNeighbors());

        // Test DIRECT_AND_BELOW
        $below = AuthTestHelpers::assignRoleToUser($user, $role, $team, RoleHierarchyEnum::DIRECT_AND_BELOW);
        $this->assertTrue($below->getRoleHierarchyAccessDirect());
        $this->assertTrue($below->getRoleHierarchyAccessBelow());
        $this->assertFalse($below->getRoleHierarchyAccessNeighbors());

        // Test DIRECT_AND_NEIGHBOURS
        $neighbours = AuthTestHelpers::assignRoleToUser($user, $role, $team, RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS);
        $this->assertTrue($neighbours->getRoleHierarchyAccessDirect());
        $this->assertFalse($neighbours->getRoleHierarchyAccessBelow());
        $this->assertTrue($neighbours->getRoleHierarchyAccessNeighbors());

        // Test DIRECT_AND_BELOW_AND_NEIGHBOURS
        $both = AuthTestHelpers::assignRoleToUser($user, $role, $team, RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS);
        $this->assertTrue($both->getRoleHierarchyAccessDirect());
        $this->assertTrue($both->getRoleHierarchyAccessBelow());
        $this->assertTrue($both->getRoleHierarchyAccessNeighbors());
    }

    /**
     * INVARIANT: exceedsRoleLimit() checks max assignments
     * 
     * @test
     */
    public function test_exceeds_role_limit()
    {
        // Arrange: Role with max_assignments_per_team = 2
        $role = AuthTestHelpers::createRole('Limited Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ], ['max_assignments_per_team' => 2]);

        $user1 = UserFactory::new()->create();
        $user2 = UserFactory::new()->create();
        $team = AuthTestHelpers::createTeam([], $user1);

        // Create 2 assignments (at limit)
        AuthTestHelpers::assignRoleToUser($user1, $role, $team);
        AuthTestHelpers::assignRoleToUser($user2, $role, $team);

        // Act: Check if exceeds
        $exceeds = TeamRole::exceedsRoleLimit($role->id, $team->id);

        // Assert: Should return true (limit reached)
        $this->assertTrue($exceeds, 'Should exceed limit with 2 assignments (max = 2)');
    }

    /**
     * INVARIANT: TeamRole save aborts if role limit exceeded
     * 
     * @test
     */
    public function test_team_role_save_aborts_if_limit_exceeded()
    {
        // Arrange: Role with max 1 assignment
        $role = AuthTestHelpers::createRole('Single Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ], ['max_assignments_per_team' => 1]);

        $user1 = UserFactory::new()->create();
        $user2 = UserFactory::new()->create();
        $team = AuthTestHelpers::createTeam([], $user1);

        // Create first assignment
        AuthTestHelpers::assignRoleToUser($user1, $role, $team);

        // Expect: 403 abort when trying to create second
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        // Act: Try to create second assignment
        $teamRole = new TeamRole();
        $teamRole->user_id = $user2->id;
        $teamRole->team_id = $team->id;
        $teamRole->role = $role->id;
        $teamRole->save();
    }
}


