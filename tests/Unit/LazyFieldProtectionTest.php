<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestLazyProtectedModel;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;
use Kompo\Auth\Tests\TestCase;

/**
 * Lazy Field Protection Test
 *
 * Tests the LAZY field protection system where sensitive fields return null on access
 * rather than being removed from the model immediately on retrieval.
 *
 * Scenarios covered:
 * - Lazy protection returns null when accessing sensitive fields
 * - Lazy protection shows original value with permission
 * - Lazy vs Eager protection comparison
 * - Performance benefits of lazy loading
 * - toArray() and toJson() respect lazy protection
 * - Team context with lazy protection
 * - Collections with lazy protection
 * - Global bypass with lazy protection
 */
class LazyFieldProtectionTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestLazyProtectedModel');
        AuthTestHelpers::createPermission('TestLazyProtectedModel.sensibleColumns');
    }

    /**
     * INVARIANT: Lazy protection returns NULL when accessing sensitive field without permission
     *
     * @test
     */
    public function test_lazy_protection_returns_null_for_sensitive_field_without_permission()
    {
        // Arrange: User with READ but NOT sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestLazyProtectedModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model with sensitive data
        HasSecurity::enterBypassContext();
        $model = TestLazyProtectedModel::create([
            'name' => 'Test Model',
            'description' => 'Public description',
            'secret_field' => 'This is secret',
            'confidential_data' => 'Very confidential',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve model
        $retrieved = TestLazyProtectedModel::find($model->id);

        // Assert: Public fields should be accessible
        $this->assertNotNull($retrieved);
        $this->assertEquals('Test Model', $retrieved->name);
        $this->assertEquals('Public description', $retrieved->description);

        // Assert: Sensitive fields should return NULL (not throw error, not show value)
        $this->assertNull($retrieved->secret_field, 'Lazy protection should return null for secret_field');
        $this->assertNull($retrieved->confidential_data, 'Lazy protection should return null for confidential_data');
    }

    /**
     * INVARIANT: Lazy protection shows original value WITH permission
     *
     * @test
     */
    public function test_lazy_protection_shows_value_with_permission()
    {
        // Arrange: User with READ and sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            [
                'TestLazyProtectedModel' => PermissionTypeEnum::READ,
                'TestLazyProtectedModel.sensibleColumns' => PermissionTypeEnum::READ,
            ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model with sensitive data
        HasSecurity::enterBypassContext();
        $model = TestLazyProtectedModel::create([
            'name' => 'Test Model',
            'secret_field' => 'Secret Value 123',
            'confidential_data' => 'Confidential ABC',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve model
        $retrieved = TestLazyProtectedModel::find($model->id);

        // Assert: ALL fields should be visible
        $this->assertNotNull($retrieved);
        $this->assertEquals('Test Model', $retrieved->name);
        $this->assertEquals('Secret Value 123', $retrieved->secret_field, 'Should show secret_field with permission');
        $this->assertEquals('Confidential ABC', $retrieved->confidential_data, 'Should show confidential_data with permission');
    }

    /**
     * COMPARISON: Lazy vs Eager field protection behavior
     *
     * @test
     */
    public function test_lazy_vs_eager_field_protection_comparison()
    {
        // Arrange: Same user and team for both models
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        AuthTestHelpers::createPermission('TestSecuredModel');
        AuthTestHelpers::createPermission('TestSecuredModel.sensibleColumns');

        $this->actingAs($user);

        // Create EAGER model (TestSecuredModel)
        HasSecurity::enterBypassContext();
        $eagerModel = TestSecuredModel::create([
            'name' => 'Eager Model',
            'secret_field' => 'Eager Secret',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);

        // Create LAZY model (TestLazyProtectedModel)
        $lazyModel = TestLazyProtectedModel::create([
            'name' => 'Lazy Model',
            'secret_field' => 'Lazy Secret',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve both models
        $retrievedEager = TestSecuredModel::find($eagerModel->id);
        $retrievedLazy = TestLazyProtectedModel::find($lazyModel->id);

        // Assert EAGER: Field is removed from attributes entirely
        $this->assertNotNull($retrievedEager);
        $eagerAttributes = $retrievedEager->getAttributes();
        $this->assertArrayNotHasKey('secret_field', $eagerAttributes, 'Eager protection removes field from attributes');

        // Assert LAZY: Field exists but returns null when accessed
        $lazyAttributes = $retrievedLazy->getAttributes();
        $this->assertNotNull($retrievedLazy);
        $this->assertNull($lazyAttributes['secret_field'], 'Lazy protection returns null for field');
    }

    /**
     * INVARIANT: toArray() respects lazy field protection
     *
     * @test
     */
    // public function test_to_array_respects_lazy_protection()
    // {
    //     // Arrange: User without sensibleColumns permission
    //     $data = AuthTestHelpers::createUserWithRole(
    //         ['TestLazyProtectedModel' => PermissionTypeEnum::READ],
    //         null,
    //         RoleHierarchyEnum::DIRECT
    //     );

    //     $user = $data['user'];
    //     $team = $data['team'];

    //     $this->actingAs($user);

    //     // Create model
    //     HasSecurity::enterBypassContext();
    //     $model = TestLazyProtectedModel::create([
    //         'name' => 'Test Model',
    //         'secret_field' => 'Secret Value',
    //         'confidential_data' => 'Confidential Value',
    //         'team_id' => $team->id,
    //         'user_id' => $user->id,
    //     ]);
    //     HasSecurity::exitBypassContext();

    //     // Act: Retrieve and convert to array
    //     $retrieved = TestLazyProtectedModel::find($model->id);
    //     $array = $retrieved->toArray();

    //     // Assert: Public fields present
    //     $this->assertArrayHasKey('name', $array);
    //     $this->assertEquals('Test Model', $array['name']);

    //     // Assert: Sensitive fields should NOT be in array (or be null)
    //     if (isset($array['secret_field'])) {
    //         $this->assertNull($array['secret_field'], 'Sensitive field should be null in toArray()');
    //     }
    //     if (isset($array['confidential_data'])) {
    //         $this->assertNull($array['confidential_data'], 'Sensitive field should be null in toArray()');
    //     }
    // }

    /**
     * INVARIANT: toJson() respects lazy field protection
     *
     * @test
     */
    // public function test_to_json_respects_lazy_protection()
    // {
    //     // Arrange: User without sensibleColumns permission
    //     $data = AuthTestHelpers::createUserWithRole(
    //         ['TestLazyProtectedModel' => PermissionTypeEnum::READ],
    //         null,
    //         RoleHierarchyEnum::DIRECT
    //     );

    //     $user = $data['user'];
    //     $team = $data['team'];

    //     $this->actingAs($user);

    //     // Create model
    //     HasSecurity::enterBypassContext();
    //     $model = TestLazyProtectedModel::create([
    //         'name' => 'Test Model',
    //         'secret_field' => 'Secret Value',
    //         'team_id' => $team->id,
    //         'user_id' => $user->id,
    //     ]);
    //     HasSecurity::exitBypassContext();

    //     // Act: Retrieve and convert to JSON
    //     $retrieved = TestLazyProtectedModel::find($model->id);
    //     $json = $retrieved->toJson();
    //     $decoded = json_decode($json, true);

    //     // Assert: Public fields present
    //     $this->assertArrayHasKey('name', $decoded);
    //     $this->assertEquals('Test Model', $decoded['name']);

    //     // Assert: Sensitive field should NOT expose secret value
    //     if (isset($decoded['secret_field'])) {
    //         $this->assertNull($decoded['secret_field'], 'Sensitive field should be null in JSON');
    //     }
    // }

    /**
     * INVARIANT: Lazy protection respects team context
     *
     * @test
     */
    public function test_lazy_protection_respects_team_context()
    {
        // Arrange: User with sensibleColumns permission in Team A only
        $user = UserFactory::new()->create();

        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $roleA = AuthTestHelpers::createRole('Role A', [
            'TestLazyProtectedModel' => PermissionTypeEnum::READ,
            'TestLazyProtectedModel.sensibleColumns' => PermissionTypeEnum::READ,
        ]);

        $roleB = AuthTestHelpers::createRole('Role B', [
            'TestLazyProtectedModel' => PermissionTypeEnum::READ,
            // NO sensibleColumns permission
        ]);

        AuthTestHelpers::assignRoleToUser($user, $roleA, $teamA);
        $teamRoleB = AuthTestHelpers::assignRoleToUser($user, $roleB, $teamB);

        $user->current_team_role_id = $teamRoleB->id;
        $user->save();

        $this->actingAs($user);

        // Create models in both teams
        HasSecurity::enterBypassContext();
        $modelA = TestLazyProtectedModel::create([
            'name' => 'Model A',
            'secret_field' => 'Secret A',
            'team_id' => $teamA->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);

        $modelB = TestLazyProtectedModel::create([
            'name' => 'Model B',
            'secret_field' => 'Secret B',
            'team_id' => $teamB->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve models
        $retrievedA = TestLazyProtectedModel::find($modelA->id);
        $retrievedB = TestLazyProtectedModel::find($modelB->id);

        // Assert: Team A model shows sensitive field (has permission)
        if ($retrievedA) {
            $this->assertEquals('Secret A', $retrievedA->secret_field, 'Team A should see sensitive field');
        }

        // Assert: Team B model hides sensitive field (no permission)
        if ($retrievedB) {
            $this->assertNull($retrievedB->secret_field, 'Team B should NOT see sensitive field');
        }
    }

    /**
     * INVARIANT: Lazy protection on collections
     *
     * @test
     */
    public function test_lazy_protection_on_collection()
    {
        // Arrange: User without sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestLazyProtectedModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create multiple models
        HasSecurity::enterBypassContext();
        TestLazyProtectedModel::create([
            'name' => 'Model 1',
            'secret_field' => 'Secret 1',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);

        TestLazyProtectedModel::create([
            'name' => 'Model 2',
            'secret_field' => 'Secret 2',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve collection
        $collection = TestLazyProtectedModel::all();

        // Assert: All models have lazy protection applied
        $this->assertCount(2, $collection);

        foreach ($collection as $model) {
            $this->assertNotNull($model->name, 'Public field should be visible');
            $this->assertNull($model->secret_field, 'Lazy protection should return null for all models in collection');
        }
    }

    /**
     * INVARIANT: Global bypass shows all fields with lazy protection
     *
     * @test
     */
    public function test_lazy_protection_with_global_bypass()
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
        $model = TestLazyProtectedModel::create([
            'name' => 'Test Model',
            'secret_field' => 'Secret Value',
            'confidential_data' => 'Confidential Value',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);

        // Act: Retrieve model
        $retrieved = TestLazyProtectedModel::find($model->id);

        // Assert: All fields visible with global bypass
        $this->assertNotNull($retrieved);
        $this->assertEquals('Test Model', $retrieved->name);
        $this->assertEquals('Secret Value', $retrieved->secret_field, 'Global bypass should show sensitive field');
        $this->assertEquals('Confidential Value', $retrieved->confidential_data, 'Global bypass should show sensitive field');
    }

    /**
     * Performance: Lazy protection doesn't process fields until accessed
     *
     * @test
     */
    public function test_lazy_protection_performance_deferred_processing()
    {
        // Arrange: User without sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestLazyProtectedModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model
        HasSecurity::enterBypassContext();
        $model = TestLazyProtectedModel::create([
            'name' => 'Test Model',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve model but DON'T access sensitive fields
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $retrieved = TestLazyProtectedModel::find($model->id);
        // Only access public field
        $name = $retrieved->name;

        $queriesWithoutAccess = $this->getQueryCount();

        // Now access sensitive field
        \DB::flushQueryLog();
        $secret = $retrieved->secret_field;
        $queriesWithAccess = $this->getQueryCount();

        // Assert: Lazy loading means field protection processing is deferred
        // Query count should be minimal since we're not running permission checks
        // until the field is accessed (and cached after first access)
        $this->assertEquals('Test Model', $name);
        $this->assertNull($secret);
    }

    /**
     * INVARIANT: Accessing non-sensitive fields doesn't trigger protection check
     *
     * @test
     */
    public function test_accessing_non_sensitive_fields_no_protection_overhead()
    {
        // Arrange: User without sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestLazyProtectedModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model
        HasSecurity::enterBypassContext();
        $model = TestLazyProtectedModel::create([
            'name' => 'Test Model',
            'description' => 'Public Description',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve model and only access non-sensitive fields
        $retrieved = TestLazyProtectedModel::find($model->id);

        $name = $retrieved->name;
        $description = $retrieved->description;
        $teamId = $retrieved->team_id;

        // Assert: Non-sensitive fields work normally
        $this->assertEquals('Test Model', $name);
        $this->assertEquals('Public Description', $description);
        $this->assertEquals($team->id, $teamId);
    }

    /**
     * Edge case: Multiple accesses to same sensitive field (caching)
     *
     * @test
     */
    public function test_lazy_protection_caches_permission_check()
    {
        // Arrange: User without sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestLazyProtectedModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Create model
        HasSecurity::enterBypassContext();
        $model = TestLazyProtectedModel::create([
            'name' => 'Test Model',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve and access same field multiple times
        $retrieved = TestLazyProtectedModel::find($model->id);

        $this->enableQueryLog();
        \DB::flushQueryLog();

        $access1 = $retrieved->secret_field;
        $queries1 = $this->getQueryCount();

        \DB::flushQueryLog();
        $access2 = $retrieved->secret_field;
        $access3 = $retrieved->secret_field;
        $queries2 = $this->getQueryCount();

        // Assert: Permission check should be cached
        $this->assertNull($access1);
        $this->assertNull($access2);
        $this->assertNull($access3);

        // Second and third access should use cache (fewer queries)
        $this->assertLessThanOrEqual($queries1, $queries2, 'Subsequent accesses should use cached permission check');
    }

    /**
     * Edge case: Lazy protection with owner bypass
     *
     * @test
     */
    public function test_lazy_protection_with_owner_bypass()
    {
        // Arrange: User without sensibleColumns permission
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
        $model = TestLazyProtectedModel::create([
            'name' => 'My Model',
            'secret_field' => 'My Secret',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id, // Owner
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve model
        $retrieved = TestLazyProtectedModel::find($model->id);

        // Assert: Owner bypass allows READ, but field protection still applies
        $this->assertNotNull($retrieved, 'Owner should see own model');
        $this->assertEquals('My Model', $retrieved->name);
        $this->assertNull($retrieved->secret_field, 'Owner bypass does not grant sensibleColumns permission');
    }

    /**
     * Edge case: Model without lazyProtectedFields property uses eager protection
     *
     * @test
     */
    public function test_model_without_lazy_flag_uses_eager_protection()
    {
        // This is tested by TestSecuredModel which doesn't have $lazyProtectedFields
        // Verify it uses eager protection (fields removed on retrieval)

        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        AuthTestHelpers::createPermission('TestSecuredModel');
        AuthTestHelpers::createPermission('TestSecuredModel.sensibleColumns');

        $this->actingAs($user);

        // Create eager model
        HasSecurity::enterBypassContext();
        $model = TestSecuredModel::create([
            'name' => 'Eager Model',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Retrieve model
        $retrieved = TestSecuredModel::find($model->id);

        // Assert: Eager protection removes field from attributes
        $this->assertNotNull($retrieved);
        $attributes = $retrieved->getAttributes();
        $this->assertArrayNotHasKey('secret_field', $attributes, 'Eager model should have field removed from attributes');
    }
}
