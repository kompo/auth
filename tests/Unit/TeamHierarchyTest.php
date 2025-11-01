<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AssertionHelpers;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;
use Kompo\Auth\Tests\TestCase;

/**
 * Team Hierarchy Test
 * 
 * Tests the RollDown system (Children/Siblings hierarchy).
 * 
 * Scenarios covered:
 * F) RollDownToChildren (DIRECT_AND_BELOW): access to descendants
 * F) RollDownToSiblings (DIRECT_AND_NEIGHBOURS): access to siblings
 * F) Combined (DIRECT_AND_BELOW_AND_NEIGHBOURS)
 * F) DENY in child blocks inherited permission
 * F) Deep hierarchies (3+ levels)
 */
class TeamHierarchyTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredModel');
    }

    /**
     * INVARIANT: DIRECT hierarchy only grants access to the assigned team
     * 
     * @test
     */
    public function test_direct_hierarchy_only_grants_access_to_assigned_team()
    {
        // Arrange: Create team hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(3);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Direct Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign with DIRECT hierarchy to Root
        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT
        );

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        // Act & Assert: Only has access to Root
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['root']->id);
        AssertionHelpers::assertTeamHierarchyDenied($teamRole, $teams['childA']->id);
        AssertionHelpers::assertTeamHierarchyDenied($teamRole, $teams['childB']->id);
        AssertionHelpers::assertTeamHierarchyDenied($teamRole, $teams['grandchildA1']->id);
    }

    /**
     * INVARIANT: DIRECT_AND_BELOW grants access to team and all descendants
     * 
     * @test
     */
    public function test_direct_and_below_grants_access_to_descendants()
    {
        // Arrange: Create team hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(3);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Below Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign with DIRECT_AND_BELOW to Root
        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        // Act & Assert: Has access to Root and ALL descendants
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['root']->id);
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['childA']->id);
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['childB']->id);
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['grandchildA1']->id);
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['grandchildA2']->id);
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['grandchildB1']->id);
    }

    /**
     * INVARIANT: DIRECT_AND_NEIGHBOURS grants access to team and siblings
     * 
     * @test
     */
    public function test_direct_and_neighbours_grants_access_to_siblings()
    {
        // Arrange: Create team hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(3);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Siblings Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign with DIRECT_AND_NEIGHBOURS to Child A
        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['childA'],
            RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS
        );

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        // Act & Assert: Has access to Child A and Child B (siblings)
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['childA']->id);
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['childB']->id, 'Should have access to sibling');

        // But NOT to Root (parent) or grandchildren
        AssertionHelpers::assertTeamHierarchyDenied($teamRole, $teams['root']->id);
        AssertionHelpers::assertTeamHierarchyDenied($teamRole, $teams['grandchildA1']->id);
        AssertionHelpers::assertTeamHierarchyDenied($teamRole, $teams['grandchildB1']->id);
    }

    /**
     * INVARIANT: DIRECT_AND_BELOW_AND_NEIGHBOURS grants access to team, descendants, and siblings
     * 
     * @test
     */
    public function test_combined_hierarchy_grants_full_access()
    {
        // Arrange: Create team hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(3);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Combined Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign with DIRECT_AND_BELOW_AND_NEIGHBOURS to Child A
        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['childA'],
            RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS
        );

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        // Act & Assert: Has access to Child A, Child B (sibling), and all grandchildren of A
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['childA']->id);
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['childB']->id, 'Sibling access');
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['grandchildA1']->id, 'Descendant access');
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['grandchildA2']->id, 'Descendant access');

        // Should NOT have access to Root or Child B's descendants (not siblings of original team)
        AssertionHelpers::assertTeamHierarchyDenied($teamRole, $teams['root']->id);
        // Note: Child B's descendants depend on whether siblings' descendants are included
        // Based on typical hierarchy logic, siblings = same level, not their children
    }

    /**
     * INVARIANT: DENY in child team blocks inherited permission
     * 
     * @test
     */
    public function test_deny_in_child_blocks_inherited_permission()
    {
        // Arrange: Create team hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $allowRole = AuthTestHelpers::createRole('Allow Role', [
            'TestSecuredModel' => PermissionTypeEnum::ALL,
        ]);

        $denyRole = AuthTestHelpers::createRole('Deny Role', [
            'TestSecuredModel' => PermissionTypeEnum::DENY,
        ]);

        // Assign ALLOW with DIRECT_AND_BELOW to Root
        AuthTestHelpers::assignRoleToUser(
            $user,
            $allowRole,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Assign DENY to Child A specifically
        $denyTeamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $denyRole,
            $teams['childA'],
            RoleHierarchyEnum::DIRECT
        );

        $user->current_team_role_id = $denyTeamRole->id;
        $user->save();

        $this->actingAs($user);

        // Act & Assert: Root should have access
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teams['root']->id
        );

        // Child A should be DENIED (local DENY overrides inherited ALLOW)
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teams['childA']->id,
            'DENY in Child A should block inherited permission from Root'
        );

        // Child B should still have access (inherited, no DENY)
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teams['childB']->id,
            'Child B should still have inherited permission'
        );
    }

    /**
     * INVARIANT: Queries filter by hierarchical teams correctly
     * 
     * @test
     */
    public function test_queries_filter_by_hierarchical_teams()
    {
        // Arrange: Create team hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Hierarchy Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign with DIRECT_AND_BELOW to Root
        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        $this->actingAs($user);

        // Create models in different teams
        $modelRoot = TestSecuredModel::create([
            'name' => 'Root Model',
            'team_id' => $teams['root']->id,
            'user_id' => $user->id,
        ]);

        $modelChildA = TestSecuredModel::create([
            'name' => 'Child A Model',
            'team_id' => $teams['childA']->id,
            'user_id' => $user->id,
        ]);

        $modelChildB = TestSecuredModel::create([
            'name' => 'Child B Model',
            'team_id' => $teams['childB']->id,
            'user_id' => $user->id,
        ]);

        // Act: Query all models
        $results = TestSecuredModel::all();

        // Assert: Should see all 3 models (Root + Children)
        $this->assertCount(3, $results, 'Should see models from Root and all children');
        $this->assertTrue($results->contains('id', $modelRoot->id));
        $this->assertTrue($results->contains('id', $modelChildA->id));
        $this->assertTrue($results->contains('id', $modelChildB->id));
    }

    /**
     * Edge case: Deep hierarchy (3+ levels)
     * 
     * @test
     */
    public function test_deep_hierarchy_propagates_permissions()
    {
        // Arrange: Create deep hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(3);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Deep Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign with DIRECT_AND_BELOW to Root
        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        // Act & Assert: Should have access to all levels
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['root']->id, 'Level 1');
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['childA']->id, 'Level 2');
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['grandchildA1']->id, 'Level 3');
        AssertionHelpers::assertTeamHierarchyAccess($teamRole, $teams['grandchildA2']->id, 'Level 3');
    }

    /**
     * Edge case: hasAccessToTeam respects hierarchy
     * 
     * @test
     */
    public function test_has_access_to_team_respects_hierarchy()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Test Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        $user->current_team_role_id = $teamRole->id;
        $user->save();

        // Act & Assert: hasAccessToTeam should return true for descendants
        $this->assertTrue(
            $user->hasAccessToTeam($teams['root']->id),
            'Should have access to root team'
        );

        $this->assertTrue(
            $user->hasAccessToTeam($teams['childA']->id),
            'Should have access to child team via hierarchy'
        );

        $this->assertTrue(
            $user->hasAccessToTeam($teams['childB']->id),
            'Should have access to child team via hierarchy'
        );
    }

    /**
     * INVARIANT: getAllTeamsWithAccess returns correct hierarchical teams
     * 
     * @test
     */
    public function test_get_all_teams_with_access_includes_hierarchical_teams()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(2);
        $user = UserFactory::new()->create();

        $role = AuthTestHelpers::createRole('Hierarchical Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        $teamRole = AuthTestHelpers::assignRoleToUser(
            $user,
            $role,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Act: Get all teams with access
        $allTeams = $teamRole->getAllTeamsWithAccess();

        // Assert: Should include Root + Children
        $this->assertCount(3, $allTeams, 'Should return root + 2 children');
        $this->assertTrue($allTeams->contains($teams['root']->id));
        $this->assertTrue($allTeams->contains($teams['childA']->id));
        $this->assertTrue($allTeams->contains($teams['childB']->id));
    }

    /**
     * Complex scenario: Multiple hierarchies with different levels
     * 
     * @test
     */
    public function test_complex_hierarchy_scenario()
    {
        // Arrange: User with multiple team roles at different hierarchy levels
        $teams = AuthTestHelpers::createTeamHierarchy(3);
        $user = UserFactory::new()->create();

        $roleA = AuthTestHelpers::createRole('Role A', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        $roleB = AuthTestHelpers::createRole('Role B', [
            'TestSecuredModel' => PermissionTypeEnum::ALL,
        ]);

        // Root with DIRECT_AND_BELOW (covers all)
        AuthTestHelpers::assignRoleToUser(
            $user,
            $roleA,
            $teams['root'],
            RoleHierarchyEnum::DIRECT_AND_BELOW
        );

        // Child A with DIRECT (redundant but specific)
        $teamRoleChildA = AuthTestHelpers::assignRoleToUser(
            $user,
            $roleB,
            $teams['childA'],
            RoleHierarchyEnum::DIRECT
        );

        $user->current_team_role_id = $teamRoleChildA->id;
        $user->save();

        $this->actingAs($user);

        // Act & Assert: Should have READ everywhere, ALL in Child A specifically
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teams['root']->id
        );

        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::ALL,
            $teams['childA']->id,
            'Should have ALL permission in Child A'
        );

        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teams['childB']->id,
            'Should have at least READ in Child B from Root role'
        );
    }
}


