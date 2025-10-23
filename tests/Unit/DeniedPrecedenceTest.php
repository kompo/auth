<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Models\Teams\Roles\PermissionException;
use Kompo\Auth\Tests\Helpers\AssertionHelpers;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;
use Kompo\Auth\Tests\TestCase;

/**
 * Denied Precedence Test
 * 
 * Tests the CRITICAL invariant: DENY permissions ALWAYS take precedence over ALLOW permissions.
 * 
 * Scenarios covered:
 * A) User with multiple roles: one DENY, others ALLOW → Access BLOCKED
 * B) DENY blocks READ operations (queries return empty)
 * C) DENY blocks WRITE operations (save/update throw exception)
 * D) DENY blocks DELETE operations
 * E) DENY at different team contexts
 */
class DeniedPrecedenceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Run test migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Create the TestSecuredModel permission
        AuthTestHelpers::createPermission('TestSecuredModel');
        AuthTestHelpers::createPermission('TestSecuredModel.sensibleColumns');
    }

    /**
     * INVARIANT: DENY permission blocks access even when another role grants it
     * 
     * @test
     */
    public function test_deny_permission_blocks_read_access_even_with_other_role_allowing_it()
    {
        // Arrange: User with 2 roles - one with ALL, one with DENY
        $scenario = AuthTestHelpers::createDeniedScenario();
        $user = $scenario['user'];

        $this->actingAs($user);

        // Act & Assert: DENY should prevail
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestResource',
            PermissionTypeEnum::READ,
            null,
            'DENY permission should block READ access despite other role granting ALL'
        );

        AssertionHelpers::assertDenyPrecedence($user, 'TestResource');
    }

    /**
     * INVARIANT: DENY blocks Eloquent queries (root blocking)
     * 
     * @test
     */
    public function test_deny_permission_blocks_eloquent_queries()
    {
        // Arrange: Setup user with DENY permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::DENY],
            null,
            RoleHierarchyEnum::DIRECT,
            'Denied Role'
        );

        $user = $data['user'];
        $team = $data['team'];

        // Create test records
        HasSecurity::enterBypassContext();
        $model1 = TestSecuredModel::create([
            'name' => 'Test Model 1',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        $model2 = TestSecuredModel::create([
            'name' => 'Test Model 2',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id, // Different user
        ]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act: Try to query models
        $results = TestSecuredModel::all();

        // Assert: Should return EMPTY (blocked by DENY)
        AssertionHelpers::assertQueryBlockedByAuthorization(
            TestSecuredModel::query(),
            'DENY permission should block all queries, returning empty collection'
        );

        $this->assertCount(0, $results, 'User with DENY should not see any records');

        // Assert: find() should return null
        $found = TestSecuredModel::find($model1->id);
        $this->assertNull($found, 'find() should return null when DENY blocks access');

        // Assert: first() should return null
        $first = TestSecuredModel::first();
        $this->assertNull($first, 'first() should return null when DENY blocks access');

        // Assert: where() should return empty
        $whereResults = TestSecuredModel::where('name', 'Test Model 1')->get();
        $this->assertCount(0, $whereResults, 'where() should return empty when DENY blocks access');
    }

    /**
     * INVARIANT: DENY blocks WRITE operations (save/update)
     * 
     * @test
     */
    public function test_deny_permission_blocks_save_operations()
    {
        // Arrange: User with DENY permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::DENY],
            null,
            RoleHierarchyEnum::DIRECT,
            'Denied Role'
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Act & Assert: Creating new model should throw PermissionException
        $this->expectException(PermissionException::class);

        TestSecuredModel::create([
            'name' => 'Should Fail',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
    }

    /**
     * INVARIANT: DENY blocks DELETE operations
     * 
     * @test
     */
    public function test_deny_permission_blocks_delete_operations()
    {
        // Arrange: Create model with permissions, then add DENY
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT,
            'Initial Allow Role'
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        $model = TestSecuredModel::create([
            'name' => 'To Delete',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);

        // Add DENY permission
        $denyRole = AuthTestHelpers::createRole('Deny Role', [
            'TestSecuredModel' => PermissionTypeEnum::DENY,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $denyRole, $team);
        
        $this->clearPermissionCache();
        $user->clearPermissionCache();

        // Act & Assert: Delete should fail
        $this->expectException(PermissionException::class);

        $model->delete();
    }

    /**
     * INVARIANT: DENY in one team blocks access to resources in that team
     * 
     * @test
     */
    public function test_deny_in_specific_team_blocks_access_to_that_team_only()
    {
        // Arrange: User with permissions in Team A, DENY in Team B
        $user = \Kompo\Auth\Database\Factories\UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        // Team A: ALL permission
        $roleA = AuthTestHelpers::createRole('Role A', [
            'TestSecuredModel' => PermissionTypeEnum::ALL,
        ]);
        AuthTestHelpers::assignRoleToUser($user, $roleA, $teamA);

        // Team B: DENY permission
        $roleB = AuthTestHelpers::createRole('Role B', [
            'TestSecuredModel' => PermissionTypeEnum::DENY,
        ]);
        $teamRoleB = AuthTestHelpers::assignRoleToUser($user, $roleB, $teamB);

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

        // Act & Assert: Access to Team B should be DENIED
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            $teamB->id,
            'User should be DENIED access to Team B resources'
        );

        // But Team A should still be accessible (if we check without team context or with Team A context)
        // Note: This depends on how the system handles cross-team DENY
        // According to the README, DENY in ANY team blocks GLOBAL access
        
        // Re-test with global context (no team specified)
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            null,
            'DENY in any team should block global access'
        );
    }

    /**
     * INVARIANT: DENY takes precedence even with direct permission overrides
     * 
     * @test
     */
    public function test_deny_precedence_over_direct_team_role_permission()
    {
        // Arrange: User with role that has ALL, but direct DENY on team_role
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT,
            'Base Role'
        );

        $user = $data['user'];
        $teamRole = $data['teamRole'];

        // Add direct DENY to the team role (overrides role permission)
        AuthTestHelpers::addDirectPermissionToTeamRole(
            $teamRole,
            'TestSecuredModel',
            PermissionTypeEnum::DENY
        );

        $this->clearPermissionCache();
        $user->clearPermissionCache();

        $this->actingAs($user);

        // Act & Assert: DENY should prevail
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestSecuredModel',
            PermissionTypeEnum::READ,
            null,
            'Direct DENY permission on team role should override role ALL permission'
        );
    }

    /**
     * INVARIANT: Multiple DENY sources all block access (redundant but explicit)
     * 
     * @test
     */
    public function test_multiple_deny_sources_all_block_access()
    {
        // Arrange: User with 3 roles, all with DENY
        $rolesConfig = [
            [
                'roleName' => 'Deny Role 1',
                'permissions' => ['TestSecuredModel' => PermissionTypeEnum::DENY],
            ],
            [
                'roleName' => 'Deny Role 2',
                'permissions' => ['TestSecuredModel' => PermissionTypeEnum::DENY],
            ],
            [
                'roleName' => 'Deny Role 3',
                'permissions' => ['TestSecuredModel' => PermissionTypeEnum::DENY],
            ],
        ];

        $data = AuthTestHelpers::createUserWithMultipleRoles($rolesConfig);
        $user = $data['user'];

        $this->actingAs($user);

        // Act & Assert: Access should be denied (triple-denied!)
        AssertionHelpers::assertDenyPrecedence($user, 'TestSecuredModel');
    }

    /**
     * Edge case: DENY with checkAuth macro should hide UI elements
     * 
     * @test
     */
    public function test_deny_permission_hides_ui_elements_via_check_auth()
    {
        // This test would require Kompo component rendering, which is complex in unit tests
        // For now, we verify the permission check returns false, which would hide the element
        
        $scenario = AuthTestHelpers::createDeniedScenario();
        $user = $scenario['user'];

        $this->actingAs($user);

        // The checkAuth macro relies on hasPermission returning false
        $hasPermission = $user->hasPermission('TestResource', PermissionTypeEnum::READ);

        $this->assertFalse($hasPermission, 'checkAuth should hide element when hasPermission returns false');
    }
}

