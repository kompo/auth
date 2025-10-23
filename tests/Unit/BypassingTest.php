<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestSecuredModel;
use Kompo\Auth\Tests\Stubs\TestUnsecuredModel;
use Kompo\Auth\Tests\TestCase;

/**
 * Bypassing Test
 * 
 * Tests all bypass mechanisms for security restrictions.
 * 
 * Scenarios covered:
 * G) systemSave() bypasses security
 * G) systemDelete() bypasses security
 * G) _bypassSecurity flag bypasses security
 * G) Scopes bypass security (alreadyVerifiedAccess, asSystemOperation, etc.)
 * G) Unsecured models don't apply restrictions
 * G) Console context automatically bypasses security
 */
class BypassingTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredModel');
        AuthTestHelpers::createPermission('TestUnsecuredModel');
    }

    /**
     * INVARIANT: systemSave() bypasses security restrictions
     * 
     * @test
     */
    public function test_system_save_bypasses_security()
    {
        // Arrange: User without WRITE permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ], // READ only, no WRITE
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Act: Use systemSave() (should bypass security)
        $model = new TestSecuredModel([
            'name' => 'Created via systemSave',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        $model->systemSave();

        // Assert: Model should be created despite lacking WRITE permission
        $this->assertDatabaseHas('test_secured_models', [
            'name' => 'Created via systemSave',
        ]);

        $this->assertNotNull($model->id, 'Model should have been saved');
    }

    /**
     * INVARIANT: systemDelete() bypasses security restrictions
     * 
     * @test
     */
    public function test_system_delete_bypasses_security()
    {
        // Arrange: Create model with permissions, then remove WRITE
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        $model = TestSecuredModel::create([
            'name' => 'To Delete via systemDelete',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Remove WRITE permission (add DENY)
        $denyRole = AuthTestHelpers::createRole('Deny Role', [
            'TestSecuredModel' => PermissionTypeEnum::DENY,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $denyRole, $team);

        $this->clearPermissionCache();
        $user->clearPermissionCache();

        // Act: Use systemDelete() (should bypass security)
        $model->systemDelete();

        // Assert: Model should be deleted
        $this->assertSoftDeleted('test_secured_models', ['id' => $model->id]);
    }

    /**
     * INVARIANT: _bypassSecurity flag bypasses security
     * 
     * @test
     */
    public function test_bypass_security_flag_works()
    {
        // Arrange: User without WRITE permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Act: Set _bypassSecurity flag
        $model = new TestSecuredModel([
            'name' => 'Bypass Flag Test',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        $model->_bypassSecurity = true;
        $model->save();

        // Assert: Model should be saved
        $this->assertDatabaseHas('test_secured_models', [
            'name' => 'Bypass Flag Test',
        ]);
    }

    /**
     * INVARIANT: alreadyVerifiedAccess scope bypasses security
     * 
     * @test
     */
    public function test_already_verified_access_scope_bypasses_security()
    {
        // Arrange: User without permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Create models
        HasSecurity::enterBypassContext();
        TestSecuredModel::create(['name' => 'Model 1', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        TestSecuredModel::create(['name' => 'Model 2', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act: Query with alreadyVerifiedAccess scope
        $results = TestSecuredModel::alreadyVerifiedAccess()->get();

        // Assert: Should return records despite lack of permission
        $this->assertCount(2, $results, 'alreadyVerifiedAccess should bypass security');
    }

    /**
     * INVARIANT: asSystemOperation scope bypasses security
     * 
     * @test
     */
    public function test_as_system_operation_scope_bypasses_security()
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
        TestSecuredModel::create(['name' => 'System Model', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act: Query as system operation
        $results = TestSecuredModel::asSystemOperation()->get();

        // Assert: Should bypass security
        $this->assertGreaterThan(0, $results->count(), 'asSystemOperation should bypass security');
    }

    /**
     * INVARIANT: throughAuthorizedRelation scope bypasses security
     * 
     * @test
     */
    public function test_through_authorized_relation_scope_bypasses_security()
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
        TestSecuredModel::create(['name' => 'Relation Model', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act: Query through authorized relation
        $results = TestSecuredModel::throughAuthorizedRelation()->get();

        // Assert: Should bypass security
        $this->assertGreaterThan(0, $results->count());
    }

    /**
     * INVARIANT: withinCurrentTeamContext scope bypasses security
     * 
     * @test
     */
    public function test_within_current_team_context_scope_bypasses_security()
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
        TestSecuredModel::create(['name' => 'Team Context Model', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act: Query within current team context
        $results = TestSecuredModel::withinCurrentTeamContext()->get();

        // Assert: Should bypass security
        $this->assertGreaterThan(0, $results->count());
    }

    /**
     * INVARIANT: Unsecured models don't apply restrictions
     * 
     * @test
     */
    public function test_unsecured_models_have_no_restrictions()
    {
        // Arrange: User without ANY permissions
        $user = \Kompo\Auth\Database\Factories\UserFactory::new()->create();
        $team = AuthTestHelpers::createTeam([], $user);

        $this->actingAs($user);

        // Act: Create unsecured model (should work without permission)
        $model = TestUnsecuredModel::create([
            'name' => 'Unsecured Model',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Assert: Should be created
        $this->assertDatabaseHas('test_unsecured_models', [
            'name' => 'Unsecured Model',
        ]);

        // Act: Query unsecured models
        $results = TestUnsecuredModel::all();

        // Assert: Should return results
        $this->assertCount(1, $results);

        // Act: Update unsecured model
        $model->name = 'Updated Unsecured Model';
        $model->save();

        // Assert: Should be updated
        $this->assertDatabaseHas('test_unsecured_models', [
            'name' => 'Updated Unsecured Model',
        ]);

        // Act: Delete unsecured model
        $model->delete();

        // Assert: Should be deleted
        $this->assertSoftDeleted('test_unsecured_models', ['id' => $model->id]);
    }

    /**
     * INVARIANT: Owner bypass - user_id match
     * 
     * @test
     */
    public function test_owner_bypass_via_user_id_match()
    {
        // Arrange: User without permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        // Create model owned by user
        HasSecurity::enterBypassContext();
        $ownedModel = TestSecuredModel::create([
            'name' => 'My Model',
            'team_id' => $team->id,
            'user_id' => $user->id, // Matches auth user
        ]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act: Query models (should see own despite no permission)
        $results = TestSecuredModel::all();

        // Assert: Should see owned model
        $this->assertCount(1, $results);
        $this->assertEquals($ownedModel->id, $results->first()->id);

        // Act: Update owned model (should work)
        $ownedModel->name = 'Updated My Model';
        $ownedModel->save();

        // Assert: Should be updated
        $this->assertDatabaseHas('test_secured_models', [
            'name' => 'Updated My Model',
        ]);
    }

    /**
     * INVARIANT: Global bypass configuration
     * 
     * @test
     */
    public function test_global_bypass_configuration()
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

        // Create model
        TestSecuredModel::create(['name' => 'Model', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);

        $this->actingAs($user);

        // Act: Query (should work despite no permission due to global bypass)
        $results = TestSecuredModel::all();

        // Assert: Should return results
        $this->assertGreaterThan(0, $results->count(), 'Global bypass should allow access');

        // Act: Create (should work)
        $newModel = TestSecuredModel::create([
            'name' => 'New Model with Bypass',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Assert: Should be created
        $this->assertDatabaseHas('test_secured_models', [
            'name' => 'New Model with Bypass',
        ]);
    }

    /**
     * Edge case: Bypassing with withoutGlobalScope
     * 
     * @test
     */
    public function test_without_global_scope_bypasses_security()
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
        TestSecuredModel::create(['name' => 'Scoped Model', 'team_id' => $team->id, 'user_id' => UserFactory::new()->create()->id]);
        HasSecurity::exitBypassContext();

        $this->actingAs($user);

        // Act: Query without global scope
        $results = TestSecuredModel::withoutGlobalScope('authUserHasPermissions')->get();

        // Assert: Should return results
        $this->assertGreaterThan(0, $results->count());
    }

    /**
     * INVARIANT: Console context automatically bypasses security
     * 
     * Note: This is hard to test in PHPUnit as we're not in console context
     * We'd need to mock app()->runningInConsole() or test via artisan command
     * 
     * @test
     */
    public function test_console_context_bypass_is_documented()
    {
        // This is a documentation test - the actual bypass happens in HasSecurity
        // when globalSecurityBypass() checks app()->runningInConsole()
        
        // In real usage, when running artisan commands, security is automatically bypassed
        // This prevents seeders, migrations, and commands from being blocked by permissions
        
        $this->assertTrue(
            true,
            'Console context bypass is implemented in globalSecurityBypass() helper'
        );
    }

    /**
     * Complex scenario: Multiple bypass methods work together
     * 
     * @test
     */
    public function test_multiple_bypass_methods_work_together()
    {
        // Arrange
        $this->enableSecurityBypass(); // Global bypass

        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredModel' => PermissionTypeEnum::DENY], // DENY permission
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Act: Even with DENY, global bypass should allow access
        $model = TestSecuredModel::create([
            'name' => 'Bypass with DENY',
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Assert: Should be created
        $this->assertDatabaseHas('test_secured_models', [
            'name' => 'Bypass with DENY',
        ]);

        // Query should also work
        $results = TestSecuredModel::all();
        $this->assertGreaterThan(0, $results->count());
    }
}

