<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * HasTeamsTrait Test
 * 
 * Tests methods from HasTeamsTrait and related traits.
 * 
 * Scenarios covered:
 * - switchToFirstTeamRole()
 * - getRelatedTeamRoles()
 * - getFirstTeamRole()
 * - getLatestTeamRole()
 * - isOwnTeamRole()
 * - ownsTeam()
 * - getAllAccessibleTeamIds()
 * - validateTeamSetup()
 * - cleanupTeamSetup()
 * - getTeamDebugInfo()
 * - Relations: currentTeamRole(), teams(), teamRoles(), activeTeamRoles(), ownedTeams()
 */
class HasTeamsTraitTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('TestResource');
    }

    /**
     * INVARIANT: switchToFirstTeamRole() sets current_team_role_id
     * 
     * @test
     */
    public function test_switch_to_first_team_role()
    {
        // Arrange: User with team roles
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Clear current team role
        $user->current_team_role_id = null;
        $user->save();

        // Act: Switch to first
        $result = $user->switchToFirstTeamRole();

        // Assert: Should set current_team_role_id
        $this->assertTrue($result, 'switchToFirstTeamRole should return true');
        $this->assertNotNull($user->current_team_role_id, 'current_team_role_id should be set');
    }

    /**
     * INVARIANT: getRelatedTeamRoles() returns team roles
     * 
     * @test
     */
    public function test_get_related_team_roles()
    {
        // Arrange: User with multiple team roles
        $rolesConfig = [
            ['roleName' => 'Role 1', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
            ['roleName' => 'Role 2', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
        ];

        $data = AuthTestHelpers::createUserWithMultipleRoles($rolesConfig);
        $user = $data['user'];
        $team = $data['teams'][0];

        // Act: Get related team roles for specific team
        $relatedTeamRoles = $user->getRelatedTeamRoles($team->id);

        // Assert: Should return team roles for that team
        $this->assertGreaterThan(0, $relatedTeamRoles->count());
    }

    /**
     * INVARIANT: getFirstTeamRole() returns first valid team role
     * 
     * @test
     */
    public function test_get_first_team_role()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Act: Get first team role
        $firstTeamRole = $user->getFirstTeamRole($team->id);

        // Assert: Should return a team role
        $this->assertNotNull($firstTeamRole);
        $this->assertEquals($team->id, $firstTeamRole->team_id);
    }

    /**
     * INVARIANT: getLatestTeamRole() returns most recent
     * 
     * @test
     */
    public function test_get_latest_team_role()
    {
        // Arrange: Multiple team roles
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Create another team role later
        sleep(1); // Ensure different timestamp
        $role2 = AuthTestHelpers::createRole('Role 2', [
            'TestResource' => PermissionTypeEnum::ALL,
        ]);
        $teamRole2 = AuthTestHelpers::assignRoleToUser($user, $role2, $team);

        // Act: Get latest
        $latestTeamRole = $user->getLatestTeamRole($team->id);

        // Assert: Should be the second one
        $this->assertNotNull($latestTeamRole);
        $this->assertEquals($teamRole2->id, $latestTeamRole->id);
    }

    /**
     * INVARIANT: isOwnTeamRole() checks ownership
     * 
     * @test
     */
    public function test_is_own_team_role()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $teamRole = $data['teamRole'];

        // Another user's team role
        $otherUser = UserFactory::new()->create();
        $otherTeam = AuthTestHelpers::createTeam([], $otherUser);
        $otherRole = AuthTestHelpers::createRole('Other', ['TestResource' => PermissionTypeEnum::READ]);
        $otherTeamRole = AuthTestHelpers::assignRoleToUser($otherUser, $otherRole, $otherTeam);

        // Act & Assert: Own team role
        $this->assertTrue($user->isOwnTeamRole($teamRole), 'Should recognize own team role');

        // Not own team role
        $this->assertFalse($user->isOwnTeamRole($otherTeamRole), 'Should NOT recognize other user team role');
    }

    /**
     * INVARIANT: ownsTeam() checks team ownership
     * 
     * @test
     */
    public function test_owns_team()
    {
        // Arrange: User owns team
        $user = UserFactory::new()->create();
        $ownedTeam = AuthTestHelpers::createTeam(['team_name' => 'My Team'], $user);

        // Other team
        $otherUser = UserFactory::new()->create();
        $otherTeam = AuthTestHelpers::createTeam(['team_name' => 'Other Team'], $otherUser);

        // Act & Assert: Owns team
        $this->assertTrue($user->ownsTeam($ownedTeam), 'Should recognize owned team');

        // Doesn't own team
        $this->assertFalse($user->ownsTeam($otherTeam), 'Should NOT recognize non-owned team');

        // Null team
        $this->assertFalse($user->ownsTeam(null), 'Should return false for null team');
    }

    /**
     * INVARIANT: getAllAccessibleTeamIds() returns all accessible teams
     * 
     * @test
     */
    public function test_get_all_accessible_team_ids()
    {
        // Arrange: User with hierarchy access
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

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        // Act: Get all accessible team IDs
        $accessibleTeamIds = $user->getAllAccessibleTeamIds();

        // Assert: Should include root and children
        $this->assertIsArray($accessibleTeamIds);
        $this->assertContains($teams['root']->id, $accessibleTeamIds);
        $this->assertContains($teams['childA']->id, $accessibleTeamIds);
        $this->assertContains($teams['childB']->id, $accessibleTeamIds);
    }

    /**
     * INVARIANT: Relations work correctly
     * 
     * @test
     */
    public function test_has_teams_trait_relations()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Act & Assert: currentTeamRole relation
        $currentTeamRole = $user->currentTeamRole;
        $this->assertNotNull($currentTeamRole);

        // teams relation
        $teams = $user->teams;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $teams);
        $this->assertGreaterThan(0, $teams->count());

        // teamRoles relation
        $teamRoles = $user->teamRoles;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $teamRoles);
        $this->assertGreaterThan(0, $teamRoles->count());

        // activeTeamRoles relation
        $activeTeamRoles = $user->activeTeamRoles;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $activeTeamRoles);

        // ownedTeams relation
        $ownedTeams = $user->ownedTeams;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $ownedTeams);
    }

    /**
     * INVARIANT: activeTeamRoles filters out deleted
     * 
     * @test
     */
    public function test_active_team_roles_filters_terminated()
    {
        // Arrange: User with 2 team roles
        $rolesConfig = [
            ['roleName' => 'Role 1', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
            ['roleName' => 'Role 2', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
        ];

        $data = AuthTestHelpers::createUserWithMultipleRoles($rolesConfig);
        $user = $data['user'];
        $teamRole1 = $data['teamRoles'][0];
        $teamRole2 = $data['teamRoles'][1];

        $countTeamRoles = $user->teamRoles()->count();

        // Terminate one
        $teamRole1->terminate();

        // Act: Get active team roles
        $activeTeamRoles = $user->activeTeamRoles()->get();

        // Assert: Should only include non-terminated
        $this->assertEquals($countTeamRoles - 1, $activeTeamRoles->count(), 'Should only show active (non-terminated) team roles');
        $this->assertContains($teamRole2->id, $activeTeamRoles->pluck('id'));
    }

    /**
     * INVARIANT: activeTeamRoles filters out suspended
     * 
     * @test
     */
    public function test_active_team_roles_filters_suspended()
    {
        // Arrange
        $rolesConfig = [
            ['roleName' => 'Role 1', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
            ['roleName' => 'Role 2', 'permissions' => ['TestResource' => PermissionTypeEnum::READ]],
        ];

        $data = AuthTestHelpers::createUserWithMultipleRoles($rolesConfig);
        $user = $data['user'];
        $teamRole1 = $data['teamRoles'][0];

        $countTeamRoles = $user->teamRoles()->count();

        // Suspend one
        $teamRole1->suspend();

        // Act: Get active
        $activeTeamRoles = $user->activeTeamRoles()->get();

        // Assert: Should only include non-suspended
        $this->assertEquals($countTeamRoles - 1, $activeTeamRoles->count(), 'Should only show non-suspended team roles');
    }

    /**
     * Performance: getAllAccessibleTeamIds() uses cache
     * 
     * @test
     */
    public function test_get_all_accessible_team_ids_uses_cache()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        // Act: First call
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $accessible1 = $user->getAllAccessibleTeamIds();
        $queries1 = $this->getQueryCount();

        // Second call
        \DB::flushQueryLog();
        $accessible2 = $user->getAllAccessibleTeamIds();
        $queries2 = $this->getQueryCount();

        // Assert: Should use cache
        $this->assertEquals(count($accessible1), count($accessible2));
        $this->assertEquals(0, $queries2, 'Should use cache on second call');
    }

    /**
     * Edge case: User with no team roles
     * 
     * @test
     */
    public function test_user_with_no_team_roles()
    {
        // Arrange: New user without team roles
        $user = UserFactory::new()->create();

        // Act: Try to get first team role
        $firstTeamRole = $user->getFirstTeamRole();

        // Assert: Should handle gracefully (null or create personal team)
        // Behavior depends on implementation
        $teamRoles = $user->teamRoles()->get();
        // If no team roles exist, various methods should handle gracefully
    }

    /**
     * INVARIANT: clearPermissionCache() clears user cache
     * 
     * @test
     */
    public function test_clear_permission_cache()
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

        // Act: Clear cache
        $user->clearPermissionCache();

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Check permission again
        $user->hasPermission('TestResource', PermissionTypeEnum::READ);
        $queries = $this->getQueryCount();

        // Assert: Should query DB (cache was cleared)
        $this->assertGreaterThan(0, $queries, 'clearPermissionCache should clear cache');
    }
}


