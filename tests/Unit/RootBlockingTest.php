<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AssertionHelpers;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;
use Kompo\Auth\Tests\TestCase;

/**
 * Root Blocking Test
 * 
 * Tests the CRITICAL invariant: Without READ permission, Eloquent queries return EMPTY/NULL.
 * 
 * Scenarios covered:
 * A) User without permission: Model::all() returns empty
 * B) User without permission: Model::find() returns null
 * C) User without permission: Model::first() returns null
 * D) User without permission: Model::where() returns empty
 * E) User with permission: queries work normally
 * F) Owner bypass: user sees own records even without global permission
 */
class RootBlockingTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredModel');
    }

    /**
     * INVARIANT: Without READ permission, Model::all() returns empty
     * 
     * @test
     */
    public function test_user_without_permission_sees_no_records_with_all()
    {
        // Arrange: User WITHOUT TestSecuredModel permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL], // Different permission
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Create test models
        HasSecurity::enterBypassContext();
        TestSecuredModel::create(['name' => 'Model 1', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        TestSecuredModel::create(['name' => 'Model 2', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        TestSecuredModel::create(['name' => 'Model 3', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act
        $results = TestSecuredModel::all();

        // Assert: Should be empty
        $this->assertCount(0, $results, 'User without permission should not see any records via all()');
        AssertionHelpers::assertQueryBlockedByAuthorization(TestSecuredModel::query());
    }

    /**
     * INVARIANT: Without READ permission, Model::find() returns null
     * 
     * @test
     */
    public function test_user_without_permission_gets_null_with_find()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        HasSecurity::enterBypassContext();
        $model = TestSecuredModel::create([
            'name' => 'Target Model',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id
        ]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act
        $found = TestSecuredModel::find($model->id);

        // Assert: Should be null
        $this->assertNull($found, 'User without permission should get null from find()');
    }

    /**
     * INVARIANT: Without READ permission, Model::first() returns null
     * 
     * @test
     */
    public function test_user_without_permission_gets_null_with_first()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        HasSecurity::enterBypassContext();
        TestSecuredModel::create(['name' => 'First Model', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act
        $first = TestSecuredModel::first();

        // Assert: Should be null
        $this->assertNull($first, 'User without permission should get null from first()');
    }

    /**
     * INVARIANT: Without READ permission, Model::where() returns empty
     * 
     * @test
     */
    public function test_user_without_permission_gets_empty_collection_with_where()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        HasSecurity::enterBypassContext();
        TestSecuredModel::create(['name' => 'Searchable Model', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act
        $results = TestSecuredModel::where('name', 'Searchable Model')->get();

        // Assert: Should be empty
        $this->assertCount(0, $results, 'User without permission should get empty collection from where()');
    }

    /**
     * POSITIVE CASE: With READ permission, queries work normally
     * 
     * @test
     */
    public function test_user_with_permission_sees_records()
    {
        // Arrange: User WITH TestSecuredModel permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Create test models in the same team
        HasSecurity::enterBypassContext();
        $model1 = TestSecuredModel::create(['name' => 'Model 1', 'team_id' => $team->id, 'user_id' => $user->id]);
        $model2 = TestSecuredModel::create(['name' => 'Model 2', 'team_id' => $team->id, 'user_id' => $user->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act & Assert: all() should return records
        $allResults = TestSecuredModel::all();
        $this->assertCount(2, $allResults, 'User with READ permission should see records via all()');

        // find() should work
        $found = TestSecuredModel::find($model1->id);
        $this->assertNotNull($found, 'User with READ permission should find records via find()');
        $this->assertEquals($model1->id, $found->id);

        // first() should work
        $first = TestSecuredModel::first();
        $this->assertNotNull($first, 'User with READ permission should get records via first()');

        // where() should work
        $whereResults = TestSecuredModel::where('name', 'Model 1')->get();
        $this->assertCount(1, $whereResults, 'User with READ permission should get records via where()');
    }

    /**
     * OWNER BYPASS: User sees own records even without global permission
     * 
     * @test
     */
    public function test_user_sees_own_records_via_owner_bypass()
    {
        // Arrange: User WITHOUT TestSecuredModel permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        HasSecurity::enterBypassContext();

        // Create model owned by user (user_id match)
        $ownedModel = TestSecuredModel::create([
            'name' => 'My Own Model',
            'team_id' => $team->id,
            'user_id' => $user->id // Owner!
        ]);

        // Create model NOT owned by user
        $otherModel = TestSecuredModel::create([
            'name' => 'Someone Elses Model',
            'team_id' => $team->id,
            'user_id' => UserFactory::new()->create()->id // Different user
        ]);

        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act
        $results = TestSecuredModel::all();

        // Assert: Should only see own record (owner bypass)
        $this->assertCount(1, $results, 'User should see own records via owner bypass');
        $this->assertEquals($ownedModel->id, $results->first()->id);

        // find() on own record should work
        $foundOwn = TestSecuredModel::find($ownedModel->id);
        $this->assertNotNull($foundOwn, 'User should find own records via owner bypass');

        // find() on other record should return null
        $foundOther = TestSecuredModel::find($otherModel->id);
        $this->assertNull($foundOther, 'User should NOT find non-owned records');
    }

    /**
     * SCOPE userOwnedRecords: Custom ownership logic
     * 
     * @test
     */
    public function test_custom_user_owned_records_scope_works()
    {
        // The TestSecuredModel has scopeUserOwnedRecords() defined
        // This test verifies it works correctly

        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Create owned and non-owned models
        HasSecurity::enterBypassContext();
        $owned1 = TestSecuredModel::create(['name' => 'Owned 1', 'team_id' => $team->id, 'user_id' => $user->id]);
        $owned2 = TestSecuredModel::create(['name' => 'Owned 2', 'team_id' => $team->id, 'user_id' => $user->id]);
        $notOwned = TestSecuredModel::create(['name' => 'Not Owned', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // The security scope should apply userOwnedRecords automatically
        $results = TestSecuredModel::all();

        $this->assertCount(2, $results, 'Should see 2 owned records');
        $this->assertTrue($results->contains('id', $owned1->id));
        $this->assertTrue($results->contains('id', $owned2->id));
        $this->assertFalse($results->contains('id', $notOwned->id));
    }

    /**
     * Edge case: Empty database with no permission
     * 
     * @test
     */
    public function test_empty_database_returns_empty_without_permission()
    {
        // Arrange: No models exist
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act
        $results = TestSecuredModel::all();

        // Assert: Should be empty (no records AND no permission)
        $this->assertCount(0, $results, 'Empty database should return empty without permission');
    }

    /**
     * RELATIONS: Related models also apply security
     * 
     * @test
     */
    public function test_relations_respect_security_scopes()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        HasSecurity::enterBypassContext();
        $model = TestSecuredModel::create([
            'name' => 'Model with Team',
            'team_id' => $team->id,
            'user_id' => $user->id
        ]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act: Access related team
        $relatedTeam = $model->team;

        // Assert: Should be accessible (depends on Team model security)
        // Note: This assumes Team model has appropriate security or bypass
        $this->assertNotNull($relatedTeam, 'Related team should be accessible');
        $this->assertEquals($team->id, $relatedTeam->id);
    }

    /**
     * Performance: Global scope is applied efficiently
     * 
     * @test
     */
    public function test_global_scope_does_not_cause_n_plus_one()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        HasSecurity::enterBypassContext();
        // Create multiple models
        for ($i = 0; $i < 40; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id
            ]);
        }
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Enable query log
        $this->enableQueryLog();

        // Act: Query all models
        $results = TestSecuredModel::all();

        // Get query count
        $queryCount = $this->getQueryCount();

        // Assert: Should be reasonable (not N+1)
        $this->assertCount(40, $results);
        $this->assertLessThanOrEqual(
            20, // There are a base given by complexity, because we get many permissions before, but they are not N+1
            $queryCount,
            'Query count should be reasonable (no N+1 from global scope)'
        );
    }
}

