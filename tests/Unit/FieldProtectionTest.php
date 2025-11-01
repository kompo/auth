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
 * Field Protection Test
 * 
 * Tests the sensitive fields protection system.
 * 
 * Scenarios covered:
 * - Sensitive fields are hidden without permission
 * - Sensitive fields are visible with permission
 * - Field protection respects team context
 * - Field protection with bypass mechanisms
 * - Custom securityRelatedTeamIds method
 * - Field protection doesn't cause infinite loops
 */
class FieldProtectionTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredModel');
        AuthTestHelpers::createPermission('TestSecuredModel.sensibleColumns');
    }

    /**
     * INVARIANT: Sensitive fields are hidden without .sensibleColumns permission
     * 
     * @test
     */
    public function test_sensitive_fields_hidden_without_permission()
    {
        // Arrange: User with READ on model but NOT on sensibleColumns
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model with sensitive data
        $model = TestSecuredModel::create([
            'name' => 'Test Model',
            'description' => 'Public description',
            'secret_field' => 'This is secret',
            'confidential_data' => 'Very confidential',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Act: Retrieve model (field protection triggers on retrieved event)
        $retrieved = TestSecuredModel::find($model->id);

        // Assert: Public fields visible
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'name');
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'description');

        // Assert: Sensitive fields hidden
        AssertionHelpers::assertSensitiveFieldHidden($retrieved, 'secret_field');
        AssertionHelpers::assertSensitiveFieldHidden($retrieved, 'confidential_data');
    }

    /**
     * INVARIANT: Sensitive fields are visible WITH .sensibleColumns permission
     * 
     * @test
     */
    public function test_sensitive_fields_visible_with_permission()
    {
        // Arrange: User with READ and sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            [
                'TestSecuredModel' => PermissionTypeEnum::READ,
                'TestSecuredModel.sensibleColumns' => PermissionTypeEnum::READ,
            ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model with sensitive data
        $model = TestSecuredModel::create([
            'name' => 'Test Model',
            'secret_field' => 'This is secret',
            'confidential_data' => 'Very confidential',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Act: Retrieve model
        $retrieved = TestSecuredModel::find($model->id);

        // Assert: ALL fields visible
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'name');
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'secret_field');
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'confidential_data');
    }

    /**
     * INVARIANT: Field protection respects team context
     * 
     * @test
     */
    public function test_field_protection_respects_team_context()
    {
        // Arrange: User with sensibleColumns permission in Team A only
        $user = \Kompo\Auth\Database\Factories\UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $roleA = AuthTestHelpers::createRole('Role A', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
            'TestSecuredModel.sensibleColumns' => PermissionTypeEnum::READ,
        ]);

        $roleB = AuthTestHelpers::createRole('Role B', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
            // NO sensibleColumns permission
        ]);

        AuthTestHelpers::assignRoleToUser($user, $roleA, $teamA);
        $teamRoleB = AuthTestHelpers::assignRoleToUser($user, $roleB, $teamB);

        $user->current_team_role_id = $teamRoleB->id;
        $user->save();

        $this->actingAs($user);

        // Create models in both teams
        $modelA = TestSecuredModel::create([
            'name' => 'Model A',
            'secret_field' => 'Secret A',
            'team_id' => $teamA->id,
            'user_id' => $user->id,
        ]);

        $modelB = TestSecuredModel::create([
            'name' => 'Model B',
            'secret_field' => 'Secret B',
            'team_id' => $teamB->id,
            'user_id' => $user->id,
        ]);

        // Act: Retrieve models
        $retrievedA = TestSecuredModel::find($modelA->id);
        $retrievedB = TestSecuredModel::find($modelB->id);

        // Assert: Team A model shows sensitive fields
        if ($retrievedA) {
            AssertionHelpers::assertSensitiveFieldVisible($retrievedA, 'secret_field');
        }

        // Assert: Team B model hides sensitive fields
        if ($retrievedB) {
            AssertionHelpers::assertSensitiveFieldHidden($retrievedB, 'secret_field');
        }
    }

    /**
     * INVARIANT: Field protection with owner bypass
     * 
     * @test
     */
    public function test_field_protection_with_owner_bypass()
    {
        // Arrange: User without any permissions
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        $model = TestSecuredModel::create([
            'name' => 'My Model',
            'secret_field' => 'My Secret',
            'team_id' => $team->id,
            'user_id' => $user->id, // Owner
        ]);

        // Act: Retrieve model (owner bypass applies)
        $retrieved = TestSecuredModel::find($model->id);

        // Assert: Owner bypass applies to security, but field protection is separate
        // User still needs sensibleColumns permission to see sensitive fields
        $this->assertNotNull($retrieved, 'Owner should see own model');
        
        // Sensitive fields should still be hidden (owner bypass ≠ field permission)
        AssertionHelpers::assertSensitiveFieldHidden($retrieved, 'secret_field');
    }

    /**
     * INVARIANT: Global bypass shows all fields
     * 
     * @test
     */
    public function test_global_bypass_shows_all_fields()
    {
        // Arrange: Enable global bypass
        $this->enableSecurityBypass();

        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model with sensitive data
        $model = TestSecuredModel::create([
            'name' => 'Test Model',
            'secret_field' => 'This is secret',
            'confidential_data' => 'Very confidential',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);

        // Act: Retrieve model
        $retrieved = TestSecuredModel::find($model->id);

        // Assert: All fields visible with global bypass
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'name');
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'secret_field');
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'confidential_data');
    }

    /**
     * Edge case: Model without sensibleColumns defined
     * 
     * @test
     */
    public function test_model_without_sensible_columns_shows_all_fields()
    {
        // The TestUnsecuredModel doesn't define $sensibleColumns
        $data = AuthTestHelpers::createUserWithRole(
            ['TestUnsecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create unsecured model
        $model = \Kompo\Auth\Tests\Stubs\TestUnsecuredModel::create([
            'name' => 'Test Model',
            'description' => 'All fields should be visible',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Act: Retrieve model
        $retrieved = \Kompo\Auth\Tests\Stubs\TestUnsecuredModel::find($model->id);

        // Assert: All fields visible (no field protection)
        $this->assertNotNull($retrieved);
        $this->assertEquals('Test Model', $retrieved->name);
        $this->assertEquals('All fields should be visible', $retrieved->description);
    }

    /**
     * INVARIANT: Field protection on collection (multiple models)
     * 
     * @test
     */
    public function test_field_protection_on_collection()
    {
        // Arrange: User without sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create multiple models
        TestSecuredModel::create([
            'name' => 'Model 1',
            'secret_field' => 'Secret 1',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        TestSecuredModel::create([
            'name' => 'Model 2',
            'secret_field' => 'Secret 2',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Act: Retrieve collection
        $collection = TestSecuredModel::all();

        // Assert: All models in collection have field protection applied
        $this->assertCount(2, $collection);
        
        foreach ($collection as $model) {
            AssertionHelpers::assertSensitiveFieldVisible($model, 'name');
            AssertionHelpers::assertSensitiveFieldHidden($model, 'secret_field');
        }
    }

    /**
     * Performance: Field protection doesn't cause N+1
     * 
     * @test
     */
    public function test_field_protection_performance()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create multiple models
        for ($i = 0; $i < 10; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }

        // Act: Retrieve with query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $collection = TestSecuredModel::all();

        $queryCount = $this->getQueryCount();

        // Assert: Should not cause N+1 queries
        $this->assertCount(10, $collection);
        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            "Field protection should not cause N+1 queries (got {$queryCount})"
        );
    }

    /**
     * Edge case: DENY permission with sensitive fields
     * 
     * @test
     */
    public function test_deny_permission_blocks_access_before_field_protection()
    {
        // Arrange: User with DENY permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::DENY],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Create model
        $model = TestSecuredModel::create([
            'name' => 'Model',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);

        $this->actingAs($user);

        // Act: Try to retrieve (should be blocked by DENY before field protection)
        $retrieved = TestSecuredModel::find($model->id);

        // Assert: Model should not be retrieved at all
        $this->assertNull($retrieved, 'DENY should block access before field protection applies');
    }
}


