<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AssertionHelpers;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;
use Kompo\Auth\Tests\Stubs\TestStrictValidationModel;
use Kompo\Auth\Tests\TestCase;

/**
 * Validate Owned As Well Test
 *
 * Tests the $validateOwnedAsWell property which disables owner bypass.
 * When enabled, even record owners must have proper permissions.
 *
 * Scenarios covered:
 * - Default behavior: Owner bypass works (backward compatible)
 * - With validateOwnedAsWell: Owner bypass disabled
 * - READ permissions: Owners can't see own records without permission
 * - WRITE permissions: Owners can't edit own records without permission
 * - DELETE permissions: Owners can't delete own records without permission
 * - Field protection: Owners don't bypass field protection
 * - scopeUserOwnedRecords: Disabled when validateOwnedAsWell is true
 * - Config-based default behavior
 */
class ValidateOwnedAsWellTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredModel');
        AuthTestHelpers::createPermission('TestSecuredModel.sensibleColumns');
        AuthTestHelpers::createPermission('TestStrictValidationModel');
        AuthTestHelpers::createPermission('TestStrictValidationModel.sensibleColumns');
    }

    /**
     * BASELINE: Default model allows owner bypass (backward compatibility)
     *
     * @test
     */
    public function test_default_model_allows_owner_bypass()
    {
        // Arrange: User WITHOUT TestSecuredModel permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        HasSecurity::enterBypassContext();
        $model = TestSecuredModel::create([
            'name' => 'My Model',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => $user->id, // Owner
        ]);
        HasSecurity::exitBypassContext();

        // Act: Try to retrieve (should work due to owner bypass)
        $retrieved = TestSecuredModel::find($model->id);

        // Assert: Owner bypass allows access
        $this->assertNotNull($retrieved, 'Default: Owner should see own record (owner bypass enabled)');
        $this->assertEquals('My Model', $retrieved->name);
    }

    /**
     * INVARIANT: With validateOwnedAsWell, owner bypass is DISABLED for READ
     *
     * @test
     */
    public function test_validate_owned_as_well_disables_owner_bypass_for_read()
    {
        // Arrange: User WITHOUT TestStrictValidationModel permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        HasSecurity::enterBypassContext();
        $model = TestStrictValidationModel::create([
            'name' => 'My Strict Model',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => $user->id, // Owner
        ]);
        HasSecurity::exitBypassContext();

        // Act: Try to retrieve (should be BLOCKED - no owner bypass)
        $retrieved = TestStrictValidationModel::find($model->id);

        // Assert: Owner bypass is disabled
        $this->assertNull($retrieved, 'validateOwnedAsWell: Owner should NOT see own record without permission');
    }

    /**
     * INVARIANT: With permission, strict validation model works normally
     *
     * @test
     */
    public function test_strict_validation_works_with_permission()
    {
        // Arrange: User WITH TestStrictValidationModel permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestStrictValidationModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        HasSecurity::enterBypassContext();
        $model = TestStrictValidationModel::create([
            'name' => 'Model with Permission',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve with proper permission
        $retrieved = TestStrictValidationModel::find($model->id);

        // Assert: Works normally with permission
        $this->assertNotNull($retrieved, 'Should see record with proper permission');
        $this->assertEquals('Model with Permission', $retrieved->name);
    }

    /**
     * INVARIANT: validateOwnedAsWell disables owner bypass for WRITE
     *
     * @test
     */
    public function test_validate_owned_as_well_disables_owner_bypass_for_write()
    {
        // Arrange: User WITHOUT WRITE permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestStrictValidationModel' => PermissionTypeEnum::READ], // Only READ
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        HasSecurity::enterBypassContext();
        $model = TestStrictValidationModel::create([
            'name' => 'Original Name',
            'team_id' => $team->id,
            'user_id' => $user->id, // Owner
        ]);
        HasSecurity::exitBypassContext();

        // Act & Assert: Try to update (should fail - no owner bypass for WRITE)
        $this->expectException(\Kompo\Auth\Models\Teams\Roles\PermissionException::class);

        $retrieved = TestStrictValidationModel::find($model->id);
        $retrieved->name = 'Updated Name';
        $retrieved->save(); // Should throw exception
    }

    /**
     * INVARIANT: validateOwnedAsWell disables owner bypass for DELETE
     *
     * @test
     */
    public function test_validate_owned_as_well_disables_owner_bypass_for_delete()
    {
        // Arrange: User with READ but no DELETE permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestStrictValidationModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        HasSecurity::enterBypassContext();
        $model = TestStrictValidationModel::create([
            'name' => 'To Delete',
            'team_id' => $team->id,
            'user_id' => $user->id, // Owner
        ]);
        HasSecurity::exitBypassContext();

        // Act & Assert: Try to delete (should fail - no owner bypass)
        $this->expectException(\Kompo\Auth\Models\Teams\Roles\PermissionException::class);

        $retrieved = TestStrictValidationModel::find($model->id);
        $retrieved->delete(); // Should throw exception
    }

    /**
     * INVARIANT: Field protection not bypassed for owners with validateOwnedAsWell
     *
     * @test
     */
    public function test_field_protection_not_bypassed_for_owners()
    {
        // Arrange: User with READ but NOT sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestStrictValidationModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        HasSecurity::enterBypassContext();
        $model = TestStrictValidationModel::create([
            'name' => 'Model',
            'secret_field' => 'My Secret',
            'team_id' => $team->id,
            'user_id' => $user->id, // Owner
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve model
        $retrieved = TestStrictValidationModel::find($model->id);

        // Assert: Field protection applies even for owners
        $this->assertNotNull($retrieved);
        AssertionHelpers::assertSensitiveFieldVisible($retrieved, 'name');
        AssertionHelpers::assertSensitiveFieldHidden($retrieved, 'secret_field');
    }

    /**
     * INVARIANT: scopeUserOwnedRecords bypass disabled with validateOwnedAsWell
     *
     * @test
     */
    public function test_scope_user_owned_records_bypass_disabled()
    {
        // Arrange: User WITHOUT permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create models - some owned, some not owned
        HasSecurity::enterBypassContext();
        $ownedModel = TestStrictValidationModel::create([
            'name' => 'Owned',
            'team_id' => $team->id,
            'user_id' => $user->id, // Owned
        ]);

        $notOwnedModel = TestStrictValidationModel::create([
            'name' => 'Not Owned',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Query all (scopeUserOwnedRecords should NOT apply)
        $results = TestStrictValidationModel::all();

        // Assert: Owner should see NOTHING (scopeUserOwnedRecords bypass disabled)
        $this->assertCount(0, $results, 'validateOwnedAsWell disables scopeUserOwnedRecords bypass');
    }

    /**
     * COMPARISON: Default vs Strict validation side-by-side
     *
     * @test
     */
    public function test_default_vs_strict_validation_comparison()
    {
        // Arrange: User WITHOUT permissions
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create both types of models, owned by user
        HasSecurity::enterBypassContext();
        $defaultModel = TestSecuredModel::create([
            'name' => 'Default Model',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        $strictModel = TestStrictValidationModel::create([
            'name' => 'Strict Model',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve both
        $retrievedDefault = TestSecuredModel::find($defaultModel->id);
        $retrievedStrict = TestStrictValidationModel::find($strictModel->id);

        // Assert: Default allows owner bypass, Strict does not
        $this->assertNotNull($retrievedDefault, 'Default model: Owner bypass works');
        $this->assertNull($retrievedStrict, 'Strict model: Owner bypass disabled');
    }

    /**
     * INVARIANT: all() query returns empty for owners without permission
     *
     * @test
     */
    public function test_all_query_empty_for_owners_without_permission()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create multiple models owned by user
        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 5; $i++) {
            TestStrictValidationModel::create([
                'name' => "Model {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id, // All owned
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Query all
        $results = TestStrictValidationModel::all();

        // Assert: Should be empty (no owner bypass)
        $this->assertCount(0, $results, 'Owner should not see any records without permission');
    }

    /**
     * INVARIANT: where() query returns empty for owners without permission
     *
     * @test
     */
    public function test_where_query_empty_for_owners_without_permission()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        HasSecurity::enterBypassContext();
        $model = TestStrictValidationModel::create([
            'name' => 'Searchable',
            'team_id' => $team->id,
            'user_id' => $user->id, // Owned
        ]);
        HasSecurity::exitBypassContext();

        // Act: Query with where
        $results = TestStrictValidationModel::where('name', 'Searchable')->get();

        // Assert: Should be empty
        $this->assertCount(0, $results, 'Owner should not find records without permission');
    }

    /**
     * INVARIANT: first() returns null for owners without permission
     *
     * @test
     */
    public function test_first_returns_null_for_owners_without_permission()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model owned by user
        HasSecurity::enterBypassContext();
        TestStrictValidationModel::create([
            'name' => 'First Model',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Query first()
        $result = TestStrictValidationModel::first();

        // Assert: Should be null
        $this->assertNull($result, 'Owner should not see first() record without permission');
    }

    /**
     * Edge case: Non-owner can't access records (same as before)
     *
     * @test
     */
    public function test_non_owner_still_blocked()
    {
        // Arrange: Two users
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user1 = $data['user'];
        $user2 = UserFactory::new()->create();
        $team = $data['team'];

        // Create model owned by user2
        HasSecurity::enterBypassContext();
        $model = TestStrictValidationModel::create([
            'name' => 'User2 Model',
            'team_id' => $team->id,
            'user_id' => $user2->id, // Owned by user2
        ]);
        HasSecurity::exitBypassContext();

        // Act: user1 tries to access user2's model
        $this->actingAs($user1);
        $retrieved = TestStrictValidationModel::find($model->id);

        // Assert: user1 can't see user2's model
        $this->assertNull($retrieved, 'Non-owner should be blocked as usual');
    }

    /**
     * INVARIANT: Global bypass still works with validateOwnedAsWell
     *
     * @test
     */
    public function test_global_bypass_works_with_validate_owned_as_well()
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

        // Create model
        $model = TestStrictValidationModel::create([
            'name' => 'Test Model',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Act: Retrieve with global bypass
        $retrieved = TestStrictValidationModel::find($model->id);

        // Assert: Global bypass overrides validateOwnedAsWell
        $this->assertNotNull($retrieved, 'Global bypass should work even with validateOwnedAsWell');
    }

    /**
     * Performance: Strict validation doesn't add query overhead
     *
     * @test
     */
    public function test_strict_validation_performance()
    {
        // Arrange: User with permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestStrictValidationModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create models
        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 10; $i++) {
            TestStrictValidationModel::create([
                'name' => "Model {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Query with logging
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $results = TestStrictValidationModel::all();

        $queryCount = $this->getQueryCount();

        // Assert: Query count should be reasonable
        $this->assertCount(10, $results);
        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            "Strict validation should not add significant query overhead (got {$queryCount})"
        );
    }
}
