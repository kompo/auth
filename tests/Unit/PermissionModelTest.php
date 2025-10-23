<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Permission Model Test
 * 
 * Tests Permission and PermissionSection models.
 * 
 * Scenarios covered:
 * - Permission::findByKey() with cache
 * - getPermissionTypeByRoleId()
 * - getUsersWithPermission()
 * - PermissionSection relations and methods
 * - hasAllPermissionsSameType()
 * - allPermissionsTypes()
 * - hasAllPermissions()
 */
class PermissionModelTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * INVARIANT: Permission::findByKey() uses cache
     * 
     * @test
     */
    public function test_find_by_key_uses_cache()
    {
        // Arrange: Create permission
        $permission = AuthTestHelpers::createPermission('CachedPermission');

        // Act: First find (no cache)
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $found1 = Permission::findByKey('CachedPermission');
        $queries1 = $this->getQueryCount();

        // Second find (should use cache)
        \DB::flushQueryLog();
        $found2 = Permission::findByKey('CachedPermission');
        $queries2 = $this->getQueryCount();

        // Assert: Both should find
        $this->assertNotFalse($found1);
        $this->assertNotFalse($found2);
        $this->assertEquals($permission->id, $found1->id);

        // Second should use cache (0 queries)
        $this->assertEquals(0, $queries2, 'findByKey should use cache');
    }

    /**
     * INVARIANT: findByKey() returns false for non-existent permission
     * 
     * @test
     */
    public function test_find_by_key_returns_false_for_non_existent()
    {
        // Act: Find non-existent permission
        $found = Permission::findByKey('NonExistentPermission');

        // Assert: Should return false (not null)
        $this->assertFalse($found, 'findByKey should return false for non-existent permission');
    }

    /**
     * INVARIANT: getPermissionTypeByRoleId() returns correct type
     * 
     * @test
     */
    public function test_get_permission_type_by_role_id()
    {
        // Arrange: Permission with role
        $permission = AuthTestHelpers::createPermission('TestPermission');
        $role = AuthTestHelpers::createRole('Test Role', [
            'TestPermission' => PermissionTypeEnum::ALL,
        ]);

        // Act: Get permission type for this role
        $type = $permission->getPermissionTypeByRoleId($role->id);

        // Assert: Should be ALL
        $this->assertEquals(PermissionTypeEnum::ALL->value, $type);
    }

    /**
     * INVARIANT: getUsersWithPermission() returns users with this permission
     * 
     * @test
     */
    public function test_get_users_with_permission()
    {
        // Arrange: Permission assigned to role, role assigned to users
        $permission = AuthTestHelpers::createPermission('SharedPermission');
        
        $data1 = AuthTestHelpers::createUserWithRole(
            ['SharedPermission' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $data2 = AuthTestHelpers::createUserWithRole(
            ['SharedPermission' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user1 = $data1['user'];
        $user2 = $data2['user'];

        // User without permission
        $user3 = UserFactory::new()->create();

        // Act: Get users with this permission
        $usersWithPermission = $permission->getUsersWithPermission();

        // Assert: Should include user1 and user2, not user3
        $this->assertGreaterThanOrEqual(2, $usersWithPermission->count());
        $this->assertTrue($usersWithPermission->contains('id', $user1->id));
        $this->assertTrue($usersWithPermission->contains('id', $user2->id));
    }

    /**
     * INVARIANT: PermissionSection::getPermissions() uses cache
     * 
     * @test
     */
    public function test_permission_section_get_permissions_uses_cache()
    {
        // Arrange: Section with permissions
        $section = PermissionSection::firstOrCreate(
            ['name' => 'Test Section']
        );
        
        $perm1 = Permission::create([
            'permission_key' => 'Perm1',
            'permission_name' => 'Permission 1',
            'permission_description' => 'Description 1',
            'permission_section_id' => $section->id,
        ]);

        $perm2 = Permission::create([
            'permission_key' => 'Perm2',
            'permission_name' => 'Permission 2',
            'permission_description' => 'Description 2',
            'permission_section_id' => $section->id,
        ]);

        // Act: Get permissions (first call)
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $permissions1 = $section->getPermissions();
        $queries1 = $this->getQueryCount();

        // Second call (should use cache)
        \DB::flushQueryLog();
        $permissions2 = $section->getPermissions();
        $queries2 = $this->getQueryCount();

        // Assert: Should use cache
        $this->assertEquals($permissions1->count(), $permissions2->count());
        $this->assertEquals(0, $queries2, 'getPermissions should use cache');
    }

    /**
     * INVARIANT: hasAllPermissionsSameType() checks uniformity
     * 
     * @test
     */
    public function test_has_all_permissions_same_type()
    {
        // Arrange: Section with permissions
        $section = PermissionSection::firstOrCreate(
            ['name' => 'Uniform Section']
        );
        
        $perm1 = Permission::create([
            'permission_key' => 'Uniform1',
            'permission_name' => 'Permission 1',
            'permission_description' => 'Uniform description 1',
            'permission_section_id' => $section->id,
        ]);

        $perm2 = Permission::create([
            'permission_key' => 'Uniform2',
            'permission_name' => 'Permission 2',
            'permission_description' => 'Uniform description 2',
            'permission_section_id' => $section->id,
        ]);

        // Role with same type for all permissions in section
        $role = AuthTestHelpers::createRole('Uniform Role', [
            'Uniform1' => PermissionTypeEnum::READ,
            'Uniform2' => PermissionTypeEnum::READ,
        ]);

        // Act: Check if all same type
        $allSameType = $section->hasAllPermissionsSameType($role);

        // Assert: Should be true
        $this->assertTrue($allSameType, 'All permissions in section should have same type');
    }

    /**
     * INVARIANT: hasAllPermissions() checks if all permissions in section are assigned
     * 
     * @test
     */
    public function test_has_all_permissions()
    {
        // Arrange: Section with 2 permissions
        $section = PermissionSection::firstOrCreate(
            ['name' => 'Complete Section']
        );
        
        $perm1 = Permission::create([
            'permission_key' => 'Complete1',
            'permission_name' => 'Permission 1',
            'permission_description' => 'Complete description 1',
            'permission_section_id' => $section->id,
        ]);

        $perm2 = Permission::create([
            'permission_key' => 'Complete2',
            'permission_name' => 'Permission 2',
            'permission_description' => 'Complete description 2',
            'permission_section_id' => $section->id,
        ]);

        // Role with BOTH permissions
        $role = AuthTestHelpers::createRole('Complete Role', [
            'Complete1' => PermissionTypeEnum::READ,
            'Complete2' => PermissionTypeEnum::READ,
        ]);

        // Act: Check if has all
        $hasAll = $section->hasAllPermissions($role);

        // Assert: Should be true
        $this->assertTrue($hasAll, 'Role should have all permissions in section');

        // Arrange: Role with only ONE permission
        $incompleteRole = AuthTestHelpers::createRole('Incomplete Role', [
            'Complete1' => PermissionTypeEnum::READ,
            // Missing Complete2
        ]);

        // Act: Check incomplete
        $hasAllIncomplete = $section->hasAllPermissions($incompleteRole);

        // Assert: Should be false
        $this->assertFalse($hasAllIncomplete, 'Role should NOT have all permissions');
    }

    /**
     * INVARIANT: PermissionSection relation to permissions
     * 
     * @test
     */
    public function test_permission_section_relation()
    {
        // Arrange
        $section = PermissionSection::firstOrCreate(
            ['name' => 'Section With Perms']
        );
        
        Permission::create([
            'permission_key' => 'SectionPerm1',
            'permission_name' => 'Permission 1',
            'permission_description' => 'Section permission 1',
            'permission_section_id' => $section->id,
        ]);

        // Act: Get permissions via relation
        $permissions = $section->permissions;

        // Assert: Should have permissions
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $permissions);
        $this->assertGreaterThan(0, $permissions->count());
    }

    /**
     * Edge case: Permission without roles
     * 
     * @test
     */
    public function test_permission_without_roles()
    {
        // Arrange: Permission not assigned to any role
        $section = PermissionSection::firstOrCreate(
            ['name' => 'Test']
        );
        
        $permission = Permission::create([
            'permission_key' => 'UnassignedPermission',
            'permission_name' => 'Unassigned',
            'permission_description' => 'Unassigned permission',
            'permission_section_id' => $section->id,
        ]);

        // Act: Get roles
        $roles = $permission->roles()->get();

        // Assert: Should be empty
        $this->assertCount(0, $roles);

        // getUsersWithPermission should also be empty
        $users = $permission->getUsersWithPermission();
        $this->assertCount(0, $users);
    }

    /**
     * INVARIANT: Permission findByKey cache can be cleared
     * 
     * @test
     */
    public function test_find_by_key_cache_clearing()
    {
        // Arrange: Create permission
        AuthTestHelpers::createPermission('CacheablePermission');

        // Populate cache
        Permission::findByKey('CacheablePermission');

        // Act: Clear cache
        Cache::forget('permission_CacheablePermission');

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Find again (should query)
        Permission::findByKey('CacheablePermission');
        $queries = $this->getQueryCount();

        // Assert: Should query DB (cache was cleared)
        $this->assertGreaterThan(0, $queries, 'Should query after cache clear');
    }
}

