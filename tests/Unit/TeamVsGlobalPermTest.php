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
 * Team vs Global Permission Test
 * 
 * Tests the distinction between team-specific and global permissions.
 * 
 * Scenarios covered:
 * E) User with permission in Team A only: access to Team A, not Team B
 * E) User with global permission: access to all teams
 * E) User with global + DENY in specific team: DENY prevails
 * E) Queries filter by team when permission is team-specific
 */
class TeamVsGlobalPermTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredModel');
    }

    /**
     * INVARIANT: Permission in Team A does not grant access to Team B
     * 
     * @test
     */
    public function test_permission_in_team_a_does_not_grant_access_to_team_b()
    {
        // Arrange: User with permission only in Team A
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $role = AuthTestHelpers::createRole('Team A Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign role only to Team A
        $teamRoleA = AuthTestHelpers::assignRoleToUser($user, $role, $teamA);
        $user->current_team_role_id = $teamRoleA->id;
        $user->save();

        $this->actingAs($user);

        // Create models in both teams
        $modelA = TestSecuredModel::create([
            'name' => 'Model in Team A',
            'team_id' => $teamA->id,
            'user_id' => $user->id,
        ]);

        $modelB = TestSecuredModel::create([
            'name' => 'Model in Team B',
            'team_id' => $teamB->id,
            'user_id' => UserFactory::new()->create()->id, // Different user to avoid owner bypass
        ]);

        // Act: Query all models
        $results = TestSecuredModel::all();

        // Assert: Should only see Team A model
        $this->assertCount(1, $results, 'Should only see models from Team A');
        $this->assertEquals($modelA->id, $results->first()->id);

        // Assert: Team-specific permission checks
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teamA->id,
            'Should have access in Team A'
        );

        AssertionHelpers::assertAccessDenied(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teamB->id,
            'Should NOT have access in Team B'
        );
    }

    /**
     * POSITIVE CASE: Global permission grants access to all teams
     * 
     * @test
     */
    public function test_global_permission_grants_access_to_all_teams()
    {
        // Arrange: User with permission in multiple teams
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $role = AuthTestHelpers::createRole('Global Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign role to both teams (simulates "global" via multiple team roles)
        AuthTestHelpers::assignRoleToUser($user, $role, $teamA);
        $teamRoleB = AuthTestHelpers::assignRoleToUser($user, $role, $teamB);
        
        $user->current_team_role_id = $teamRoleB->id;
        $user->save();

        $this->actingAs($user);

        // Create models in both teams
        $modelA = TestSecuredModel::create([
            'name' => 'Model in Team A',
            'team_id' => $teamA->id,
            'user_id' => $user->id,
        ]);

        $modelB = TestSecuredModel::create([
            'name' => 'Model in Team B',
            'team_id' => $teamB->id,
            'user_id' => $user->id,
        ]);

        // Act: Query all models
        $results = TestSecuredModel::all();

        // Assert: Should see models from BOTH teams
        $this->assertCount(2, $results, 'Should see models from all teams with permission');
        $this->assertTrue($results->contains('id', $modelA->id));
        $this->assertTrue($results->contains('id', $modelB->id));

        // Assert: Global permission check (no team specified)
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            null,
            'Should have global access'
        );
    }

    /**
     * INVARIANT: DENY in specific team blocks access even with global permission
     * 
     * @test
     */
    public function test_deny_in_specific_team_blocks_access_despite_global_permission()
    {
        // Arrange: User with permission in Team A and Team B, but DENY in Team B
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $allowRole = AuthTestHelpers::createRole('Allow Role', [
            'TestSecuredModel' => PermissionTypeEnum::ALL,
        ]);

        $denyRole = AuthTestHelpers::createRole('Deny Role', [
            'TestSecuredModel' => PermissionTypeEnum::DENY,
        ]);

        // Assign allow to Team A, deny to Team B
        AuthTestHelpers::assignRoleToUser($user, $allowRole, $teamA);
        $teamRoleB = AuthTestHelpers::assignRoleToUser($user, $denyRole, $teamB);

        $user->current_team_role_id = $teamRoleB->id;
        $user->save();

        $this->actingAs($user);

        // Assert: Team A should have access
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teamA->id,
            'Should have access in Team A'
        );

        // Assert: Team B should be DENIED
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teamB->id,
            'Should be DENIED access in Team B despite allow role'
        );

        // Assert: Global check (no team specified) should consider the DENY
        // According to README: "DENY in any team blocks global access"
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            null,
            'DENY in any team should block global access'
        );
    }

    /**
     * INVARIANT: Team filtering works correctly in queries
     * 
     * @test
     */
    public function test_team_filtering_in_queries()
    {
        // Arrange: User with permission in Team A
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);
        $teamC = AuthTestHelpers::createTeam(['team_name' => 'Team C'], $user);

        $role = AuthTestHelpers::createRole('Team A Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        $teamRoleA = AuthTestHelpers::assignRoleToUser($user, $role, $teamA);
        $user->current_team_role_id = $teamRoleA->id;
        $user->save();

        $this->actingAs($user);

        // Create models in all teams
        $modelA1 = TestSecuredModel::create(['name' => 'A1', 'team_id' => $teamA->id, 'user_id' => $user->id]);
        $modelA2 = TestSecuredModel::create(['name' => 'A2', 'team_id' => $teamA->id, 'user_id' => $user->id]);
        $modelB1 = TestSecuredModel::create(['name' => 'B1', 'team_id' => $teamB->id, 'user_id' => UserFactory::new()->create()->id]);
        $modelC1 = TestSecuredModel::create(['name' => 'C1', 'team_id' => $teamC->id, 'user_id' => UserFactory::new()->create()->id]);

        // Act: Query all
        $results = TestSecuredModel::all();

        // Assert: Only Team A models
        $this->assertCount(2, $results, 'Should only see Team A models');
        $this->assertTrue($results->contains('id', $modelA1->id));
        $this->assertTrue($results->contains('id', $modelA2->id));
        $this->assertFalse($results->contains('id', $modelB1->id));
        $this->assertFalse($results->contains('id', $modelC1->id));
    }

    /**
     * Edge case: User in multiple teams with different permission levels
     * 
     * @test
     */
    public function test_user_with_different_permissions_across_teams()
    {
        // Arrange: User with READ in Team A, ALL in Team B
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $readRole = AuthTestHelpers::createRole('Read Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        $allRole = AuthTestHelpers::createRole('All Role', [
            'TestSecuredModel' => PermissionTypeEnum::ALL,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $readRole, $teamA);
        $teamRoleB = AuthTestHelpers::assignRoleToUser($user, $allRole, $teamB);

        $user->current_team_role_id = $teamRoleB->id;
        $user->save();

        // Assert: Team A has READ only
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teamA->id
        );

        AssertionHelpers::assertAccessDenied(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::WRITE,
            $teamA->id,
            'Team A should only have READ, not WRITE'
        );

        // Assert: Team B has ALL (including WRITE)
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teamB->id
        );

        AssertionHelpers::assertAccessGranted(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::WRITE,
            $teamB->id,
            'Team B should have ALL permission (including WRITE)'
        );
    }

    /**
     * INVARIANT: Team context in hasPermission is respected
     * 
     * @test
     */
    public function test_has_permission_respects_team_context()
    {
        // Arrange
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $role = AuthTestHelpers::createRole('Team A Only', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $role, $teamA);

        // Act: Check permission with different team contexts
        $hasInTeamA = $user->hasPermission('TestSecuredModel', PermissionTypeEnum::READ, $teamA->id);
        $hasInTeamB = $user->hasPermission('TestSecuredModel', PermissionTypeEnum::READ, $teamB->id);
        $hasGlobal = $user->hasPermission('TestSecuredModel', PermissionTypeEnum::READ, null);

        // Assert: Team A yes, Team B no
        $this->assertTrue($hasInTeamA, 'Should have permission in Team A');
        $this->assertFalse($hasInTeamB, 'Should NOT have permission in Team B');
        
        // Global check depends on implementation - if user has permission in ANY team, might return true
        // Or if it checks ALL teams and requires ALL to have permission, might return false
        // Based on README, global check without team means checking across all user's teams
        $this->assertTrue($hasGlobal, 'Global check should return true if user has permission in any team');
    }

    /**
     * INVARIANT: getTeamsIdsWithPermission returns correct teams
     * 
     * @test
     */
    public function test_get_teams_ids_with_permission()
    {
        // Arrange
        $user = UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);
        $teamC = AuthTestHelpers::createTeam(['team_name' => 'Team C'], $user);

        $role = AuthTestHelpers::createRole('AB Role', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
        ]);

        // Assign to Team A and B, not C
        AuthTestHelpers::assignRoleToUser($user, $role, $teamA);
        AuthTestHelpers::assignRoleToUser($user, $role, $teamB);

        // Act: Get teams with permission
        $teamsWithPermission = $user->getTeamsIdsWithPermission(
            'TestSecuredModel',
            PermissionTypeEnum::READ
        );

        // Assert: Should contain Team A and B, not C
        $this->assertCount(2, $teamsWithPermission);
        $this->assertTrue($teamsWithPermission->contains($teamA->id));
        $this->assertTrue($teamsWithPermission->contains($teamB->id));
        $this->assertFalse($teamsWithPermission->contains($teamC->id));
    }
}

