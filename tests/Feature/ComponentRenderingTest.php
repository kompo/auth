<?php

namespace Kompo\Auth\Tests\Feature;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\Stubs\TestSecuredComponent;
use Kompo\Auth\Tests\Stubs\TestUnsecuredComponent;
use Kompo\Auth\Tests\TestCase;

/**
 * Component Rendering Test
 * 
 * Tests component rendering with authorization using Kompo's boot system.
 * 
 * Scenarios covered:
 * - Component boots successfully with permission
 * - Component boot fails without permission (403)
 * - Elements are rendered correctly with permission
 * - checkAuth hides elements without permission
 * - Unsecured components always render
 * - findById and findByClass work correctly
 */
class ComponentRenderingTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        AuthTestHelpers::createPermission('TestSecuredComponent');
    }

    /**
     * INVARIANT: Component boots successfully WITH permission
     * 
     * @test
     */
    public function test_component_boots_with_permission()
    {
        // Arrange: User with TestSecuredComponent permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Boot component
        try {
            $component = TestSecuredComponent::boot();
            $booted = true;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $booted = false;
        }

        // Assert: Should boot successfully
        $this->assertTrue($booted, 'Component should boot with READ permission');
    }

    /**
     * INVARIANT: Component boot fails WITHOUT permission
     * 
     * @test
     */
    public function test_component_boot_fails_without_permission()
    {
        // Arrange: User without TestSecuredComponent permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Expect: AuthorizationException when booting
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        // Act: Try to boot component
        TestSecuredComponent::boot();
    }

    /**
     * INVARIANT: Elements are rendered correctly
     * 
     * @test
     */
    public function test_component_elements_rendered_correctly()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Boot and find elements
        $component = TestSecuredComponent::boot();

        // Find by ID
        $title = $component->findById('secured-component-title');
        $nameInput = $component->findById('name-input');
        $saveButton = $component->findById('save-button');

        // Assert: Elements exist
        $this->assertNotNull($title, 'Title element should exist');
        $this->assertNotNull($nameInput, 'Name input should exist');
        $this->assertNotNull($saveButton, 'Save button should exist');
    }

    /**
     * INVARIANT: checkAuth hides elements without permission
     * 
     * @test
     */
    public function test_check_auth_hides_element_without_permission()
    {
        // Arrange: User with READ but not ALL permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Boot component
        $component = TestSecuredComponent::boot();

        // Find delete button (requires ALL permission via checkAuth)
        $deleteButton = $component->findById('delete-button');

        // Assert: Delete button should be hidden/null (no ALL permission)
        // Note: checkAuth returns null when permission check fails
        $this->assertNull(
            $deleteButton,
            'Delete button should be hidden without ALL permission'
        );
    }

    /**
     * POSITIVE CASE: Element visible with sufficient permission
     * 
     * @test
     */
    public function test_check_auth_shows_element_with_permission()
    {
        // Arrange: User with ALL permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredComponent' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Boot component
        $component = TestSecuredComponent::boot();

        // Find delete button
        $deleteButton = $component->findById('delete-button');

        // Assert: Delete button should be visible
        $this->assertNotNull(
            $deleteButton,
            'Delete button should be visible with ALL permission'
        );
    }

    /**
     * INVARIANT: Unsecured components always boot
     * 
     * @test
     */
    public function test_unsecured_component_boots_without_permission()
    {
        // Arrange: User without any permissions
        $user = \Kompo\Auth\Database\Factories\UserFactory::new()->create();

        $this->actingAs($user);

        // Act: Boot unsecured component (should work)
        try {
            $component = TestUnsecuredComponent::boot();
            $booted = true;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $booted = false;
        }

        // Assert: Should boot successfully
        $this->assertTrue($booted, 'Unsecured component should boot without permission');
    }

    /**
     * INVARIANT: Global bypass allows component boot
     * 
     * @test
     */
    public function test_global_bypass_allows_component_boot()
    {
        // Arrange: Enable global bypass
        $this->enableSecurityBypass();

        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Boot component (should work with bypass)
        try {
            $component = TestSecuredComponent::boot();
            $booted = true;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $booted = false;
        }

        // Assert: Should boot successfully with global bypass
        $this->assertTrue($booted, 'Component should boot with global bypass');
    }

    /**
     * INVARIANT: DENY permission blocks component
     * 
     * @test
     */
    public function test_deny_permission_blocks_component()
    {
        // Arrange: User with DENY permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredComponent' => PermissionTypeEnum::DENY],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Expect: AuthorizationException
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        // Act: Try to boot
        TestSecuredComponent::boot();
    }

    /**
     * Helper: findById method
     * 
     * @test
     */
    public function test_find_by_id_helper_works()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestSecuredComponent' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Boot and find
        $component = TestSecuredComponent::boot();

        $title = $component->findElById('secured-component-title');

        // Assert: Element found
        $this->assertNotNull($title, 'findById should find element');
        $this->assertEquals('secured-component-title', $title->id);
    }

    /**
     * Edge case: Component with custom permission key
     * 
     * @test
     */
    public function test_component_with_custom_permission_key()
    {
        // Create a component class with custom permission key
        $customComponent = new class extends \Kompo\Form {
            protected $permissionKey = 'CustomPermissionKey';

            public function render()
            {
                return [_Html('Custom Component')];
            }
        };

        // Create permission
        AuthTestHelpers::createPermission('CustomPermissionKey');

        // User with custom permission
        $data = AuthTestHelpers::createUserWithRole(
            ['CustomPermissionKey' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Boot should work with custom permission key
        try {
            $component = $customComponent::boot();
            $booted = true;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $booted = false;
        }

        // Assert: Should boot with custom permission
        $this->assertTrue($booted, 'Component should boot with custom permission key');
    }

    // findById helper inherited from TestCase (ActiviteDeFinancement pattern)
}

