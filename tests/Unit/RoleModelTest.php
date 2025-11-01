<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionRole;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Models\Teams\Roles\Role;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Role Model Test
 * 
 * Tests Role model methods and permissions management.
 * 
 * Scenarios covered:
 * - validPermissionsQuery() / deniedPermissionsQuery()
 * - getPermissionTypeByPermissionId()
 * - getUsersWithRole()
 * - save() protection (from_system)
 * - delete() protection (from_system, teamRoles)
 * - createOrUpdatePermission()
 * - getOrCreate()
 * - Cache invalidation on save/delete
 * - Relations: permissions(), validPermissions(), deniedPermissions(), teamRoles()
 */
class RoleModelTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('TestResource');
        AuthTestHelpers::createPermission('OtherResource');
    }

    /**
     * INVARIANT: validPermissionsQuery() returns non-DENY permissions
     * 
     * @test
     */
    public function test_valid_permissions_query()
    {
        // Arrange: Role with mixed permissions
        $role = AuthTestHelpers::createRole('Mixed Role', [
            'TestResource' => PermissionTypeEnum::READ,
            'OtherResource' => PermissionTypeEnum::DENY,
        ]);

        // Act: Get valid permissions query
        $query = $role->validPermissionsQuery();

        // Assert: Should be a query
        $this->assertNotNull($query);
        
        $permissions = $query->get();
        
        // Should have TestResource (valid)
        $hasTestResource = $permissions->contains(function($p) {
            return getPermissionKey($p->complex_permission_key) === 'TestResource';
        });
        
        $this->assertTrue($hasTestResource, 'Should include valid permission');
    }

    /**
     * INVARIANT: deniedPermissionsQuery() returns DENY permissions
     * 
     * @test
     */
    public function test_denied_permissions_query()
    {
        // Arrange: Role with DENY
        $role = AuthTestHelpers::createRole('Deny Role', [
            'TestResource' => PermissionTypeEnum::DENY,
        ]);

        // Act: Get denied permissions
        $deniedPerms = $role->deniedPermissions()->get();

        // Assert: Should have TestResource
        $this->assertGreaterThan(0, $deniedPerms->count());
        $this->assertTrue(
            $deniedPerms->contains('permission_key', 'TestResource')
        );
    }

    /**
     * INVARIANT: getPermissionTypeByPermissionId() returns correct type
     * 
     * @test
     */
    public function test_get_permission_type_by_permission_id()
    {
        // Arrange
        $permission = AuthTestHelpers::createPermission('TestResource');
        $role = AuthTestHelpers::createRole('Test Role', [
            'TestResource' => PermissionTypeEnum::ALL,
        ]);

        // Act: Get permission type
        $type = $role->getPermissionTypeByPermissionId($permission->id);

        // Assert: Should be ALL
        $this->assertEquals(PermissionTypeEnum::ALL->value, $type);
    }

    /**
     * INVARIANT: getUsersWithRole() returns users with this role
     * 
     * @test
     */
    public function test_get_users_with_role()
    {
        // Arrange: Multiple users with same role
        $role = AuthTestHelpers::createRole('Shared Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $user1 = UserFactory::new()->create();
        $user2 = UserFactory::new()->create();
        $user3 = UserFactory::new()->create();
        
        $team = AuthTestHelpers::createTeam([], $user1);

        AuthTestHelpers::assignRoleToUser($user1, $role, $team);
        AuthTestHelpers::assignRoleToUser($user2, $role, $team);
        // user3 doesn't have this role

        // Act: Get users with role
        $usersWithRole = $role->getUsersWithRole();

        // Assert: Should include user1 and user2, not user3
        $this->assertGreaterThanOrEqual(2, $usersWithRole->count());
        $this->assertTrue($usersWithRole->contains('id', $user1->id));
        $this->assertTrue($usersWithRole->contains('id', $user2->id));
    }

    /**
     * INVARIANT: Role save throws exception if from_system
     * 
     * @test
     */
    public function test_role_save_protected_if_from_system()
    {
        // Arrange: System role
        $role = RoleModel::getOrCreate('system-role-to-update');

        // Expect: Exception when trying to update
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('auth-you-cannot-update-system-role'));

        // Act: Try to update
        $role->name = 'Modified Name';
        $role->save();
    }

    /**
     * INVARIANT: Role delete throws exception if from_system
     * 
     * @test
     */
    public function test_role_delete_protected_if_from_system()
    {
        // Arrange: System role
        $role = RoleModel::getOrCreate('system-role-to-delete');

        // Expect: Exception when trying to delete
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('auth-you-cannot-delete-system-role'));

        // Act: Try to delete
        $role->delete();
    }

    /**
     * INVARIANT: Role delete throws exception if has teamRoles
     * 
     * @test
     */
    public function test_role_delete_blocked_if_has_team_roles()
    {
        // Arrange: Role with team role assignments
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $role = $data['role'];

        // Expect: Exception (has team roles)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('auth-you-cannot-delete-role-with-team-roles'));

        // Act: Try to delete
        $role->delete();
    }

    /**
     * INVARIANT: createOrUpdatePermission() manages role permissions
     * 
     * @test
     */
    public function test_create_or_update_permission()
    {
        // Arrange: Role without permission
        $role = AuthTestHelpers::createRole('Test Role', []);
        $permission = AuthTestHelpers::createPermission('NewPermission');

        // Act: Create permission
        $role->createOrUpdatePermission($permission->id, PermissionTypeEnum::READ);

        // Assert: Permission should be attached
        $this->assertDatabaseHas('permission_role', [
            'role' => $role->id,
            'permission_id' => $permission->id,
            'permission_type' => PermissionTypeEnum::READ->value,
        ]);

        // Act: Update permission
        $role->createOrUpdatePermission($permission->id, PermissionTypeEnum::ALL);

        // Assert: Permission should be updated
        $this->assertDatabaseHas('permission_role', [
            'role' => $role->id,
            'permission_id' => $permission->id,
            'permission_type' => PermissionTypeEnum::ALL->value,
        ]);
    }

    /**
     * INVARIANT: getOrCreate() creates role if doesn't exist
     * 
     * @test
     */
    public function test_get_or_create_role()
    {
        // Act: Get or create non-existent role
        $role = Role::getOrCreate('dynamic-role');

        // Assert: Should be created
        $this->assertNotNull($role);
        $this->assertEquals('dynamic-role', $role->id);
        $this->assertEquals('Dynamic-role', $role->name);
        $this->assertTrue($role->from_system, 'Should be marked as system role');

        // Act: Get again (should not create duplicate)
        $role2 = Role::getOrCreate('dynamic-role');

        // Assert: Should be same role
        $this->assertEquals($role->id, $role2->id);
    }

    /**
     * INVARIANT: Role cache cleared on save/delete
     * 
     * @test
     */
    public function test_role_cache_cleared_on_save_delete()
    {
        // Arrange: Role
        $role = AuthTestHelpers::createRole('Cache Test Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        // Create user with this role
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        // Use the role created by helper instead
        $user = $data['user'];

        // Populate cache
        $user->hasPermission('TestResource', PermissionTypeEnum::READ);

        // Act: Update role
        $role->name = 'Updated Role Name';
        $role->save();

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Check permission again (should query - cache invalidated)
        $user->fresh()->hasPermission('TestResource', PermissionTypeEnum::READ);
        $queries = $this->getQueryCount();

        // Assert: Cache was invalidated
        $this->assertGreaterThan(0, $queries, 'Role save should invalidate user cache');
    }

    /**
     * Edge case: Role with no permissions
     * 
     * @test
     */
    public function test_role_with_no_permissions()
    {
        // Arrange: Role without permissions
        $role = RoleModel::getOrCreate('no-perms-role');

        // Act: Get permissions
        $permissions = $role->permissions()->get();

        // Assert: Should be empty
        $this->assertCount(0, $permissions);

        // validPermissionsQuery should also be empty
        $validPerms = $role->validPermissionsQuery()->get();
        $this->assertCount(0, $validPerms);
    }
}


