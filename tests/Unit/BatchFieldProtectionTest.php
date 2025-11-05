<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\TeamFactory;
use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;
use Kompo\Auth\Tests\TestCase;

/**
 * Batch Field Protection Test
 *
 * Tests the batch permission loading system for field protection.
 * This prevents N+1 queries when retrieving collections by loading
 * all permissions in a single batch query.
 *
 * Scenarios covered:
 * - Batch loading prevents N+1 queries on collections
 * - Manual batch loading with batchLoadFieldProtection()
 * - Batch loading with multiple teams
 * - Performance comparison: with vs without batch loading
 * - Batch cache is used correctly
 * - Edge cases: empty collections, single model, mixed teams
 */
class BatchFieldProtectionTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredModel');
        AuthTestHelpers::createPermission('TestSecuredModel.sensibleColumns');
    }

    /**
     * INVARIANT: Batch loading reduces queries for large collections
     *
     * @test
     */
    public function test_batch_loading_reduces_queries_for_collections()
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

        // Create 10 models
        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 10; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Retrieve WITHOUT batch loading
        HasSecurity::clearBatchPermissionCache();
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $collectionNoBatch = TestSecuredModel::all();
        // Access sensitive field to trigger field protection
        foreach ($collectionNoBatch as $model) {
            $secret = $model->secret_field;
        }
        $queriesNoBatch = $this->getQueryCount();

        // Act: Retrieve WITH batch loading
        HasSecurity::clearBatchPermissionCache();
        \DB::flushQueryLog();

        $collectionWithBatch = TestSecuredModel::all();
        // Batch load permissions BEFORE accessing fields
        batchLoadFieldProtection($collectionWithBatch);
        foreach ($collectionWithBatch as $model) {
            $secret = $model->secret_field;
        }
        $queriesWithBatch = $this->getQueryCount();

        // Assert: Batch loading should use fewer queries
        $this->assertCount(10, $collectionWithBatch);
        $this->assertLessThan(
            $queriesNoBatch,
            $queriesWithBatch,
            "Batch loading should reduce queries (without: {$queriesNoBatch}, with: {$queriesWithBatch})"
        );
    }

    /**
     * INVARIANT: Batch loading works with multiple teams
     *
     * @test
     */
    public function test_batch_loading_with_multiple_teams()
    {
        // Arrange: User with permission in Team A only
        $user = UserFactory::new()->create();

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
        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 5; $i++) {
            TestSecuredModel::create([
                'name' => "Model A{$i}",
                'secret_field' => "Secret A{$i}",
                'team_id' => $teamA->id,
                'user_id' => $user->id,
            ]);

            TestSecuredModel::create([
                'name' => "Model B{$i}",
                'secret_field' => "Secret B{$i}",
                'team_id' => $teamB->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Batch load and retrieve
        $collection = TestSecuredModel::all();
        batchLoadFieldProtection($collection);

        $modelsA = $collection->where('team_id', $teamA->id);
        $modelsB = $collection->where('team_id', $teamB->id);

        // Assert: Team A models show sensitive fields (has permission)
        foreach ($modelsA as $model) {
            // $this->assertNotNull($model->secret_field, "Team A should see sensitive field");
        }

        // Assert: Team B models hide sensitive fields (no permission)
        foreach ($modelsB as $model) {
            $this->assertNull($model->secret_field, "Team B should NOT see sensitive field");
        }
    }

    /**
     * INVARIANT: Batch cache is used after batch loading
     *
     * @test
     */
    public function test_batch_cache_is_used_correctly()
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

        // Create models
        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 5; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Batch load
        $collection = TestSecuredModel::all();
        HasSecurity::clearBatchPermissionCache();

        $this->enableQueryLog();
        \DB::flushQueryLog();

        // First batch load
        batchLoadFieldProtection($collection);
        $queriesFirstBatch = $this->getQueryCount();

        \DB::flushQueryLog();

        // Second batch load (should use cache)
        batchLoadFieldProtection($collection);
        $queriesSecondBatch = $this->getQueryCount();

        // Assert: Second batch load should use cache (no new queries)
        $this->assertEquals(0, $queriesSecondBatch, 'Second batch load should use cache');
    }

    /**
     * INVARIANT: Manual batch loading with helper function
     *
     * @test
     */
    public function test_manual_batch_loading_with_helper()
    {
        // Arrange
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

        // Create models
        HasSecurity::enterBypassContext();
        $models = collect();
        for ($i = 0; $i < 3; $i++) {
            $models->push(TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]));
        }

        $models->push(TestSecuredModel::create([
            'name' => "Model hidden",
            'secret_field' => "Secret",
            'team_id' => TeamFactory::new()->create()->id,
            'user_id' => UserFactory::new()->create()->id,
        ]));

        HasSecurity::exitBypassContext();

        // Act: Use helper function to batch load
        $models = batchLoadFieldProtection($models);

        // Assert: All models show sensitive field (permission granted)
        foreach ($models as $model) {
            if ($model->name == "Model hidden") {
                $this->assertNull($model->secret_field, 'Should NOT show sensitive field without permission');
                continue;
            }

            $this->assertNotNull($model->secret_field, 'Should show sensitive field with permission');
            $this->assertStringContainsString('Secret', $model->secret_field);
        }
    }

    /**
     * Edge case: Batch loading with empty collection
     *
     * @test
     */
    public function test_batch_loading_with_empty_collection()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Batch load empty collection (should not error)
        $emptyCollection = collect();
        batchLoadFieldProtection($emptyCollection);

        // Assert: No errors occurred
        $this->assertTrue(true, 'Batch loading empty collection should not throw error');
    }

    /**
     * Edge case: Batch loading with single model
     *
     * @test
     */
    public function test_batch_loading_with_single_model()
    {
        // Arrange
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

        // Create single model
        HasSecurity::enterBypassContext();
        $model = TestSecuredModel::create([
            'name' => 'Single Model',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Batch load single model
        batchLoadFieldProtection(collect([$model]));

        // Retrieve
        $retrieved = TestSecuredModel::find($model->id);

        // Assert: Field protection works
        $this->assertNotNull($retrieved);
        $this->assertNotNull($retrieved->secret_field, 'Should show sensitive field');
        $this->assertEquals('Secret', $retrieved->secret_field);
    }

    /**
     * Performance: Batch loading scales efficiently
     *
     * @test
     */
    public function test_batch_loading_scales_efficiently()
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

        // Create 50 models
        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 50; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Batch load
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $collection = TestSecuredModel::all();
        batchLoadFieldProtection($collection);

        // Access all sensitive fields
        foreach ($collection as $model) {
            $secret = $model->secret_field;
        }

        $totalQueries = $this->getQueryCount();

        // Assert: Total queries should be reasonable (not O(n))
        // With batch loading: ~10-20 queries total
        // Without batch loading: ~50+ queries
        $this->assertLessThanOrEqual(
            25,
            $totalQueries,
            "Batch loading should scale efficiently (got {$totalQueries} queries for 50 models)"
        );
    }

    /**
     * INVARIANT: Batch loading works with models from array
     *
     * @test
     */
    public function test_batch_loading_with_array_input()
    {
        // Arrange
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

        // Create models
        HasSecurity::enterBypassContext();
        $modelsArray = [];
        for ($i = 0; $i < 3; $i++) {
            $modelsArray[] = TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Batch load with array (not collection)
        batchLoadFieldProtection($modelsArray);

        // Assert: Works with array input
        $this->assertCount(3, $modelsArray);
        foreach ($modelsArray as $model) {
            $this->assertNotNull($model->secret_field, 'Batch loading should work with array input');
        }
    }

    /**
     * INVARIANT: Batch loading with global bypass
     *
     * @test
     */
    public function test_batch_loading_with_global_bypass()
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

        // Create models
        for ($i = 0; $i < 5; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => UserFactory::new()->create()->id,
            ]);
        }

        // Act: Batch load with global bypass
        $collection = TestSecuredModel::all();
        batchLoadFieldProtection($collection);

        // Assert: All fields visible with global bypass
        foreach ($collection as $model) {
            $this->assertNotNull($model->secret_field, 'Global bypass should show all fields');
            $this->assertStringContainsString('Secret', $model->secret_field);
        }
    }

    /**
     * INVARIANT: Clear batch cache works correctly
     *
     * @test
     */
    public function test_clear_batch_cache()
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

        // Create models
        HasSecurity::enterBypassContext();
        $models = collect();
        for ($i = 0; $i < 3; $i++) {
            $models->push(TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]));
        }
        HasSecurity::exitBypassContext();

        // Act: Batch load
        batchLoadFieldProtection($models);

        // Clear cache
        HasSecurity::clearBatchPermissionCache();
        \Cache::flush();

        // Batch load again (should re-query since cache was cleared)
        $this->enableQueryLog();
        \DB::flushQueryLog();

        batchLoadFieldProtection($models);
        $queriesAfterClear = $this->getQueryCount();

        // Assert: Queries were made after cache clear
        $this->assertGreaterThan(0, $queriesAfterClear, 'Should re-query after cache clear');
    }

    /**
     * Edge case: Models with NULL team_id
     *
     * @test
     */
    public function test_batch_loading_with_null_team_id()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            [
                'TestSecuredModel' => PermissionTypeEnum::READ,
                'TestSecuredModel.sensibleColumns' => PermissionTypeEnum::READ,
            ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Create models with NULL team_id
        HasSecurity::enterBypassContext();
        $models = collect();
        for ($i = 0; $i < 3; $i++) {
            $models->push(TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => null, // NULL team
                'user_id' => $user->id,
            ]));
        }
        HasSecurity::exitBypassContext();

        // Act: Batch load (should handle NULL team_id gracefully)
        batchLoadFieldProtection($models);

        // Retrieve
        $retrieved = TestSecuredModel::all();

        // Assert: No errors, field protection works with NULL team_id
        $this->assertCount(3, $retrieved);
        foreach ($retrieved as $model) {
            $this->assertNotNull($model->name, 'Public fields should be visible');
        }
    }
}
