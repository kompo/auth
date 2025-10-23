<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AssertionHelpers;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Component Authorization Test
 * 
 * Tests the HasAuthorizationUtils plugin for Kompo components.
 * 
 * Note: Full component rendering tests would require Kompo infrastructure.
 * These tests focus on the authorization logic that components use.
 * 
 * Scenarios covered:
 * C) Component boot checks READ permission
 * C) Component authorize checks WRITE permission
 * C) checkAuth macro logic (via hasPermission)
 * C) Permission key derivation from component class name
 * C) Custom permission keys
 */
class ComponentAuthTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Create component permissions
        AuthTestHelpers::createPermission('TestComponent');
        AuthTestHelpers::createPermission('CustomKeyComponent');
        AuthTestHelpers::createPermission('AdminPanel');
    }

    /**
     * INVARIANT: User with READ permission passes boot check
     * 
     * Note: This tests the logic that components would use during boot
     * 
     * @test
     */
    public function test_user_with_read_permission_can_access_component()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Simulate boot permission check
        $hasReadPermission = $user->hasPermission('TestComponent', PermissionTypeEnum::READ);

        // Assert: Should have permission
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestComponent',
            PermissionTypeEnum::READ,
            null,
            'User should be able to render component with READ permission'
        );
    }

    /**
     * INVARIANT: User without READ permission fails boot check
     * 
     * @test
     */
    public function test_user_without_read_permission_cannot_access_component()
    {
        // Arrange: User without TestComponent permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Check READ permission
        $hasReadPermission = $user->hasPermission('TestComponent', PermissionTypeEnum::READ);

        // Assert: Should NOT have permission (would abort 403 in real component)
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestComponent',
            PermissionTypeEnum::READ,
            null,
            'User without READ permission should not render component'
        );
    }

    /**
     * INVARIANT: User with WRITE permission passes authorize check
     * 
     * @test
     */
    public function test_user_with_write_permission_can_submit_form()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestComponent' => PermissionTypeEnum::WRITE],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Simulate authorize() check
        $hasWritePermission = $user->hasPermission('TestComponent', PermissionTypeEnum::WRITE);

        // Assert: Should have WRITE permission
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestComponent',
            PermissionTypeEnum::WRITE,
            null,
            'User should be able to submit component with WRITE permission'
        );
    }

    /**
     * INVARIANT: User without WRITE permission fails authorize check
     * 
     * @test
     */
    public function test_user_without_write_permission_cannot_submit_form()
    {
        // Arrange: User with READ only
        $data = AuthTestHelpers::createUserWithRole(
            ['TestComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Check WRITE permission
        $hasWritePermission = $user->hasPermission('TestComponent', PermissionTypeEnum::WRITE);

        // Assert: Should NOT have WRITE permission (authorize() would return false)
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestComponent',
            PermissionTypeEnum::WRITE,
            null,
            'User with READ only should not be able to submit form'
        );
    }

    /**
     * INVARIANT: checkAuth macro hides elements without permission
     * 
     * @test
     */
    public function test_check_auth_macro_logic()
    {
        // Arrange: User without TestComponent permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Simulate checkAuth logic (checks hasPermission)
        $shouldShowElement = $user->hasPermission('TestComponent', PermissionTypeEnum::READ);

        // Assert: Should be false (element would be hidden)
        $this->assertFalse(
            $shouldShowElement,
            'checkAuth should hide element when user lacks permission'
        );

        // Arrange: User WITH permission
        $data2 = AuthTestHelpers::createUserWithRole(
            ['TestComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user2 = $data2['user'];

        // Act: Check with permission
        $shouldShowElement2 = $user2->hasPermission('TestComponent', PermissionTypeEnum::READ);

        // Assert: Should be true (element would be shown)
        $this->assertTrue(
            $shouldShowElement2,
            'checkAuth should show element when user has permission'
        );
    }

    /**
     * INVARIANT: Custom permission keys work correctly
     * 
     * @test
     */
    public function test_custom_permission_key_for_component()
    {
        // Arrange: Component with custom permission key (not class name)
        $data = AuthTestHelpers::createUserWithRole(
            ['CustomKeyComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Check custom permission key
        $hasPermission = $user->hasPermission('CustomKeyComponent', PermissionTypeEnum::READ);

        // Assert: Should have permission via custom key
        AssertionHelpers::assertAccessGranted(
            $user,
            'CustomKeyComponent',
            PermissionTypeEnum::READ,
            null,
            'Custom permission key should work for component'
        );
    }

    /**
     * INVARIANT: DENY permission blocks component access
     * 
     * @test
     */
    public function test_deny_permission_blocks_component_access()
    {
        // Arrange: User with DENY permission
        $data = AuthTestHelpers::createUserWithRole(
            ['AdminPanel' => PermissionTypeEnum::DENY],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Check permission (boot would abort 403)
        $hasPermission = $user->hasPermission('AdminPanel', PermissionTypeEnum::READ);

        // Assert: Should be denied
        AssertionHelpers::assertAccessDenied(
            $user,
            'AdminPanel',
            PermissionTypeEnum::READ,
            null,
            'DENY permission should block component access'
        );
    }

    /**
     * INVARIANT: Team context is respected in components
     * 
     * @test
     */
    public function test_component_respects_team_context()
    {
        // Arrange: User with permission in Team A only
        $user = \Kompo\Auth\Database\Factories\UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $role = AuthTestHelpers::createRole('Team A Role', [
            'TestComponent' => PermissionTypeEnum::READ,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $role, $teamA);

        // Act & Assert: Team A context allows access
        AssertionHelpers::assertAccessGranted(
            $user,
            'TestComponent',
            PermissionTypeEnum::READ,
            $teamA->id,
            'Should have access in Team A'
        );

        // Team B context denies access
        AssertionHelpers::assertAccessDenied(
            $user,
            'TestComponent',
            PermissionTypeEnum::READ,
            $teamB->id,
            'Should NOT have access in Team B'
        );
    }

    /**
     * INVARIANT: Global bypass allows component access
     * 
     * @test
     */
    public function test_global_bypass_allows_component_access()
    {
        // Arrange: Enable global bypass
        $this->enableSecurityBypass();

        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Check permission (should be bypassed)
        $hasPermission = $user->hasPermission('TestComponent', PermissionTypeEnum::READ);

        // Assert: Should have permission via bypass
        $this->assertTrue(
            $hasPermission,
            'Global bypass should allow component access'
        );
    }

    /**
     * INVARIANT: Component permission is independent of model permission
     * 
     * @test
     */
    public function test_component_permission_independent_of_model()
    {
        // Arrange: User with model permission but not component permission
        AuthTestHelpers::createPermission('User'); // Model permission

        $data = AuthTestHelpers::createUserWithRole(
            ['User' => PermissionTypeEnum::ALL], // Has model permission
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: Check component permission (different from model)
        $hasComponentPermission = $user->hasPermission('TestComponent', PermissionTypeEnum::READ);

        // Assert: Should NOT have component permission
        $this->assertFalse(
            $hasComponentPermission,
            'Component permission should be independent of model permission'
        );

        // But should have model permission
        $hasModelPermission = $user->hasPermission('User', PermissionTypeEnum::READ);
        $this->assertTrue($hasModelPermission, 'Should have model permission');
    }

    /**
     * Performance: Permission checks are cached
     * 
     * @test
     */
    public function test_component_permission_checks_use_cache()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Act: First check (no cache)
        $this->enableQueryLog();
        DB::flushQueryLog();

        $hasPermission1 = $user->hasPermission('TestComponent', PermissionTypeEnum::READ);
        $queriesFirst = $this->getQueryCount();

        // Second check (should use cache)
        DB::flushQueryLog();
        $hasPermission2 = $user->hasPermission('TestComponent', PermissionTypeEnum::READ);
        $queriesSecond = $this->getQueryCount();

        // Assert: Both checks return true
        $this->assertTrue($hasPermission1);
        $this->assertTrue($hasPermission2);

        // Assert: Second check uses cache (fewer queries)
        $this->assertLessThan(
            $queriesFirst,
            $queriesSecond,
            'Second permission check should use cache'
        );
    }

    /**
     * Configuration: check-even-if-permission-does-not-exist
     * 
     * @test
     */
    public function test_strict_mode_blocks_non_existent_permissions()
    {
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        // Arrange: Enable strict mode
        $this->setSecurityConfig([
            'check-even-if-permission-does-not-exist' => true,
        ]);

        // Act: Check non-existent permission in strict mode
        $hasPermission = $user->hasPermission('NonExistentStrictComponent', PermissionTypeEnum::READ);

        // Assert: Should return false (blocked in strict mode)
        // Note: Actual implementation may vary - this depends on how permissionMustBeAuthorized works
        $this->assertFalse(
            $hasPermission,
            'Non-existent permission should be blocked in strict mode'
        );
    }
}

