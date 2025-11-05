<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Support\SecuredModelCollection;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;
use Kompo\Auth\Tests\TestCase;

/**
 * Auto-Batch Field Protection Test
 *
 * Tests AUTOMATIC batch loading of field protection permissions.
 * Security-first: Auto-batching is ENABLED by default, opt-out available.
 *
 * Scenarios covered:
 * - Automatic batch loading prevents N+1 queries (NO manual call needed)
 * - Query count validation for collections (10, 50, 100 models)
 * - all(), get(), where()->get() auto-batch
 * - find() does NOT batch (single model)
 * - Opt-out with ->withoutBatchedFieldProtection()
 * - Opt-in with ->withBatchedFieldProtection() after opt-out
 * - Multiple teams batch correctly
 * - Performance comparison: manual vs automatic
 */
class AutoBatchFieldProtectionTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredModel');
        AuthTestHelpers::createPermission('TestSecuredModel.sensibleColumns');

        Config::set('kompo-auth.security.batch-protected-fields', true);
    }

    /**
     * CRITICAL: Auto-batch prevents N+1 queries WITHOUT manual calls
     *
     * @test
     */
    public function test_auto_batch_prevents_n_plus_one_automatically()
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
        for ($i = 0; $i < 50; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Retrieve WITHOUT manual batch call
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $models = TestSecuredModel::all(); // AUTO-BATCH happens here!

        // Access sensitive fields (this would cause N+1 without auto-batch)
        foreach ($models as $model) {
            $secret = $model->secret_field;
        }

        $queryCount = $this->getQueryCount();

        // Assert: Should use minimal queries (NOT 50+)
        $this->assertCount(50, $models);
        $this->assertLessThanOrEqual(
            30,
            $queryCount,
            "Auto-batch should prevent N+1 (got {$queryCount} queries for 50 models)"
        );
    }

    /**
     * INVARIANT: Query count scales linearly with auto-batch (not exponentially)
     *
     * @test
     */
    public function test_query_count_scales_with_auto_batch()
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

        // Test with different collection sizes
        $testSizes = [10, 50];

        $queryCounts = [];

        foreach ($testSizes as $size) {
            // Clean up and create models
            \DB::table('test_secured_models')->truncate();
            HasSecurity::clearBatchPermissionCache();

            HasSecurity::enterBypassContext();
            for ($i = 0; $i < $size; $i++) {
                TestSecuredModel::create([
                    'name' => "Model {$i}",
                    'secret_field' => "Secret {$i}",
                    'team_id' => $team->id,
                    'user_id' => $user->id,
                ]);
            }
            HasSecurity::exitBypassContext();

            // Measure queries
            $this->enableQueryLog();
            \DB::flushQueryLog();

            $models = TestSecuredModel::all();
            foreach ($models as $model) {
                $secret = $model->secret_field;
            }

            $queryCounts[$size] = $this->getQueryCount();
        }

        // Assert: Query count should NOT scale linearly with model count
        // With auto-batch: 10 models ~= 50 models in query count
        $this->assertLessThanOrEqual(
            $queryCounts[10] + 3, // Allow slight increase
            $queryCounts[50],
            "Query count should not increase significantly (10 models: {$queryCounts[10]}, 50 models: {$queryCounts[50]})"
        );
    }

    /**
     * INVARIANT: get() auto-batches
     *
     * @test
     */
    public function test_get_method_auto_batches()
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

        $this->mockBatchService($this->atLeastOnce());

        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 30; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Use get()
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $models = TestSecuredModel::where('name', 'LIKE', 'Model%')->get(); // AUTO-BATCH

        foreach ($models as $model) {
            $secret = $model->secret_field;
        }

        $queryCount = $this->getQueryCount();

        // Assert
        $this->assertCount(30, $models);
        $this->assertLessThanOrEqual(20, $queryCount, "get() should auto-batch");
    }

    /**
     * INVARIANT: find() does NOT batch (single model optimization)
     *
     * @test
     */
    public function test_find_does_not_batch_single_model()
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

        $this->mockBatchService($this->never());

        HasSecurity::enterBypassContext();
        $model = TestSecuredModel::create([
            'name' => 'Single Model',
            'secret_field' => 'Secret',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act: Use find() (single model)
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $found = TestSecuredModel::find($model->id);
        $secret = $found->secret_field;

        $queryCount = $this->getQueryCount();

        // Assert: Single model shouldn't trigger heavy batching
        $this->assertNotNull($found);
    }

    /**
     * INVARIANT: Opt-out with ->withoutBatchedFieldProtection()
     *
     * @test
     */
    public function test_opt_out_with_without_batched_field_protection()
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

        $this->mockBatchService($this->never());

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

        // Act: Disable auto-batching
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $models = TestSecuredModel::withoutBatchedFieldProtection()->get();

        foreach ($models as $model) {
            $secret = $model->secret_field;
        }

        $queryCount = $this->getQueryCount();

        // Assert: WITHOUT batching, query count should be higher
        // Note: This test verifies the opt-out works
        $this->assertCount(5, $models);
        // Without batching, we'd expect more queries (but still cached per permission check)
    }

    /**
     * INVARIANT: Opt-in after opt-out with ->withBatchedFieldProtection()
     *
     * @test
     */
    public function test_opt_in_after_opt_out()
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

        $this->mockBatchService($this->atLeastOnce());

        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 40; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Disable then re-enable
        SecuredModelCollection::disableAutoBatching();
        SecuredModelCollection::enableAutoBatching();

        $this->enableQueryLog();
        \DB::flushQueryLog();

        $models = TestSecuredModel::all(); // Should auto-batch again

        foreach ($models as $model) {
            $secret = $model->secret_field;
        }

        $queryCount = $this->getQueryCount();

        // Assert: After re-enabling, auto-batch should work
        $this->assertCount(40, $models);
        $this->assertLessThanOrEqual(20, $queryCount, "Should auto-batch after re-enabling");
    }

    /**
     * INVARIANT: Auto-batch works with multiple teams
     *
     * @test
     */
    public function test_auto_batch_with_multiple_teams()
    {
        // Arrange: User with different permissions in different teams
        $user = UserFactory::new()->create();

        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $roleA = AuthTestHelpers::createRole('Role A', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
            'TestSecuredModel.sensibleColumns' => PermissionTypeEnum::READ,
        ]);

        $roleB = AuthTestHelpers::createRole('Role B', [
            'TestSecuredModel' => PermissionTypeEnum::READ,
            // NO sensibleColumns
        ]);

        AuthTestHelpers::assignRoleToUser($user, $roleA, $teamA);
        AuthTestHelpers::assignRoleToUser($user, $roleB, $teamB);

        $this->actingAs($user);

        // Create models in both teams
        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 20; $i++) {
            TestSecuredModel::create([
                'name' => "Model A{$i}",
                'secret_field' => "Secret A{$i}",
                'team_id' => $teamA->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act: Auto-batch should handle multiple teams
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $models = TestSecuredModel::all(); // AUTO-BATCH for both teams

        $queryCountSingle = $this->getQueryCount();

        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 20; $i++) {
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

        \DB::flushQueryLog();
        \Cache::flush();
        $models = TestSecuredModel::all(); // AUTO-BATCH for both teams

        $queryCountMulti = $this->getQueryCount();

        // Assert: Should batch for both teams efficiently
        $this->assertCount(60, $models);
        $this->assertLessThanOrEqual($queryCountSingle + 5, $queryCountMulti, "Auto-batch should handle multiple teams efficiently");
    }

    /**
     * Performance: 100 models with auto-batch
     *
     * @test
     */
    public function test_performance_with_100_models()
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

        $this->mockBatchService($this->atLeastOnce());

        HasSecurity::enterBypassContext();
        for ($i = 0; $i < 100; $i++) {
            TestSecuredModel::create([
                'name' => "Model {$i}",
                'secret_field' => "Secret {$i}",
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);
        }
        HasSecurity::exitBypassContext();

        // Act
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $models = TestSecuredModel::all();

        foreach ($models as $model) {
            $secret = $model->secret_field;
        }

        $queryCount = $this->getQueryCount();

        // Assert: Should handle 100 models efficiently
        $this->assertCount(100, $models);
        $this->assertLessThanOrEqual(
            20,
            $queryCount,
            "100 models should use ≤20 queries with auto-batch (got {$queryCount})"
        );
    }

    /**
     * INVARIANT: Custom collection is used
     *
     * @test
     */
    public function test_uses_secured_model_collection()
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

        HasSecurity::enterBypassContext();
        TestSecuredModel::create([
            'name' => 'Model',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);
        HasSecurity::exitBypassContext();

        // Act
        $collection = TestSecuredModel::all();

        // Assert: Should be SecuredModelCollection
        $this->assertInstanceOf(
            SecuredModelCollection::class,
            $collection,
            'Should use SecuredModelCollection for auto-batching'
        );
    }

    /**
     * Edge case: Empty collection
     *
     * @test
     */
    public function test_auto_batch_with_empty_collection()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Query with no results
        $models = TestSecuredModel::all();

        // Assert: Should not error
        $this->assertCount(0, $models);
        $this->assertInstanceOf(SecuredModelCollection::class, $models);
    }

    protected function mockBatchService($expects = null, $modelClassConstraint = TestSecuredModel::class)
    {
        $securityServiceFactory = app()->make(\Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory::class);
        $services = $securityServiceFactory->createServicesForModel(TestSecuredModel::class);

        $originalService = $services['batchPermission'];

        $batchPermissionMock = $this->createMock(\Kompo\Auth\Models\Plugins\Services\BatchPermissionService::class);
        $batchPermissionMock->expects($expects)
            ->method('batchLoadFieldProtectionPermissions')
            ->willReturnCallback(function ($models) use ($originalService) {
                return $originalService->batchLoadFieldProtectionPermissions($models);
            });

        $securityServiceFactoryMock = $this->createMock(\Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory::class);
        $securityServiceFactoryMock->method('createServicesForModel')
            ->willReturnCallback(function ($modelClass) use ($services, $expects, $batchPermissionMock) {
                $services['batchPermission'] = $batchPermissionMock;

                return $services;
            });

        $securityServiceFactoryMock->method('createBatchPermissionServiceForModel')
            ->with($this->equalTo($modelClassConstraint))
            ->willReturn($batchPermissionMock);

        $securityServiceFactoryMock->method('createFieldProtectionService')
            ->willReturn($services['fieldProtection']);

        $this->app->instance(\Kompo\Auth\Models\Plugins\Services\SecurityServiceFactory::class, $securityServiceFactoryMock);
    }
}
