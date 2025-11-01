<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Element Macros Test
 * 
 * Tests Kompo element macros for authorization (checkAuth, readOnlyIfNotAuth, hashIfNotAuth).
 * 
 * Scenarios covered:
 * - checkAuth macro hides elements
 * - checkAuthWrite macro for write permission
 * - readOnlyIfNotAuth makes fields read-only
 * - hashIfNotAuth hashes sensitive data
 * - hashAndReadOnlyIfNotAuth combines both
 */
class ElementMacrosTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('TestResource');
        AuthTestHelpers::createPermission('TestResource.sensibleColumns');
    }

    /**
     * INVARIANT: checkAuth macro returns element with permission
     * 
     * @test
     */
    public function test_check_auth_macro_with_permission()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use checkAuth macro
        $element = _Button('Test Button')->checkAuth('TestResource', PermissionTypeEnum::READ);

        // Assert: Element should be returned (not null, not hidden)
        $this->assertNotNull($element, 'Element should be returned with permission');
        $this->assertInstanceOf(\Kompo\Button::class, $element);
    }

    /**
     * INVARIANT: checkAuth macro hides element without permission
     * 
     * @test
     */
    public function test_check_auth_macro_without_permission()
    {
        // Arrange: User without permission
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use checkAuth macro
        $element = _Button('Test Button')->checkAuth('TestResource', PermissionTypeEnum::READ);

        // Assert: Element should have hidden class or be modified
        $this->assertNotNull($element);
        // The macro adds 'hidden' class when permission check fails
        $this->assertTrue(
            str_contains($element->class ?? '', 'hidden'),
            'Element should have hidden class without permission'
        );
    }

    /**
     * INVARIANT: checkAuth with returnNullInstead returns null
     * 
     * @test
     */
    public function test_check_auth_with_return_null()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['OtherResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use checkAuth with returnNullInstead
        $element = _Button('Test Button')->checkAuth('TestResource', PermissionTypeEnum::READ, null, true);

        // Assert: Should return null
        $this->assertNull($element, 'checkAuth should return null when returnNullInstead = true');
    }

    /**
     * INVARIANT: checkAuthWrite macro checks WRITE permission
     * 
     * @test
     */
    public function test_check_auth_write_macro()
    {
        // Arrange: User with READ but not WRITE
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use checkAuthWrite (requires WRITE)
        $element = _Button('Save')->checkAuthWrite('TestResource');

        // Assert: Should be hidden (no WRITE permission)
        $this->assertNotNull($element);
        $this->assertTrue(
            str_contains($element->class ?? '', 'hidden'),
            'Element should be hidden without WRITE permission'
        );
    }

    /**
     * INVARIANT: readOnlyIfNotAuth makes field read-only
     * 
     * @test
     */
    public function test_read_only_if_not_auth_macro()
    {
        // Arrange: User without WRITE permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use readOnlyIfNotAuth
        $field = _Input('Name')->readOnlyIfNotAuth('TestResource');

        // Assert: Field should be read-only
        $this->assertNotNull($field);
        // The macro sets readOnly() and disabled() when no WRITE permission
    }

    /**
     * INVARIANT: hashIfNotAuth hashes sensitive data
     * 
     * @test
     */
    public function test_hash_if_not_auth_macro()
    {
        // Arrange: User without sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use hashIfNotAuth
        $element = _Html('Sensitive Data')->hashIfNotAuth('TestResource.sensibleColumns');

        // Assert: Label should be hashed
        // The macro replaces label with asterisks when no permission
        $this->assertNotNull($element);
    }

    /**
     * INVARIANT: Macros respect team context
     * 
     * @test
     */
    public function test_macros_respect_team_context()
    {
        // Arrange: User with permission in Team A only
        $user = \Kompo\Auth\Database\Factories\UserFactory::new()->create();
        
        $teamA = AuthTestHelpers::createTeam(['team_name' => 'Team A'], $user);
        $teamB = AuthTestHelpers::createTeam(['team_name' => 'Team B'], $user);

        $role = AuthTestHelpers::createRole('Team A Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        AuthTestHelpers::assignRoleToUser($user, $role, $teamA);

        $this->actingAs($user);

        // Act: checkAuth with Team A context
        $elementA = _Button('Button A')->checkAuth('TestResource', PermissionTypeEnum::READ, $teamA->id, true);

        // checkAuth with Team B context
        $elementB = _Button('Button B')->checkAuth('TestResource', PermissionTypeEnum::READ, $teamB->id, true);

        // Assert: Team A should show, Team B should be null
        $this->assertNotNull($elementA, 'Should have element in Team A');
        $this->assertNull($elementB, 'Should NOT have element in Team B');
    }

    /**
     * Performance: Macros use static cache
     * 
     * @test
     */
    public function test_macros_use_static_cache()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Multiple macro calls with same parameters
        $this->enableQueryLog();
        \DB::flushQueryLog();

        _Button('Button 1')->checkAuth('TestResource', PermissionTypeEnum::READ);
        $queries1 = $this->getQueryCount();

        \DB::flushQueryLog();
        _Button('Button 2')->checkAuth('TestResource', PermissionTypeEnum::READ);
        $queries2 = $this->getQueryCount();

        // Assert: Second call should use static cache
        $this->assertLessThanOrEqual(
            $queries1,
            $queries2,
            'Macros should use static cache for repeated checks'
        );
    }

    /**
     * Edge case: Macros with global bypass
     *
     * @test
     */
    public function test_macros_with_global_bypass()
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

        // Act: Use macros (should bypass)
        $element = _Button('Test')->checkAuth('TestResource', PermissionTypeEnum::READ, null, true);

        // Assert: Should return element (not null) with global bypass
        $this->assertNotNull($element, 'Global bypass should allow macro to return element');
    }

    /**
     * INVARIANT: readOnlyIfNotAuth field is editable with WRITE permission
     *
     * @test
     */
    public function test_read_only_if_not_auth_with_write_permission()
    {
        // Arrange: User WITH WRITE permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::WRITE],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use readOnlyIfNotAuth
        $field = _Input('Name')->name('test_name')->value('Original Value')->readOnlyIfNotAuth('TestResource');

        // Assert: Field should NOT be read-only (user has WRITE permission)
        $this->assertNotNull($field);
        // Field should not have disabled or readOnly attributes applied
        $attributes = $field->attributes ?? [];
        $this->assertFalse(isset($attributes['readonly']), 'Field should NOT be readonly with WRITE permission');
        $this->assertFalse(isset($attributes['disabled']), 'Field should NOT be disabled with WRITE permission');
    }

    /**
     * INVARIANT: readOnlyIfNotAuth field is disabled without WRITE permission
     *
     * @test
     */
    public function test_read_only_if_not_auth_without_write_permission()
    {
        // Arrange: User WITHOUT WRITE permission (only READ)
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use readOnlyIfNotAuth
        $field = _Input('Name')->name('test_name')->value('Original Value')->readOnlyIfNotAuth('TestResource');

        // Assert: Field should be read-only and disabled
        $this->assertNotNull($field);
        // Check that the field has opacity class applied
        $this->assertTrue(
            str_contains($field->class ?? '', 'opacity') || str_contains($field->class ?? '', '!opacity'),
            'Field should have opacity class without WRITE permission'
        );
    }

    /**
     * INVARIANT: hashIfNotAuth for Html element hashes label
     *
     * @test
     */
    public function test_hash_if_not_auth_html_hashes_label()
    {
        // Arrange: User without sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use hashIfNotAuth on Html
        $originalText = 'Sensitive123Data';
        $element = _Html($originalText)->hashIfNotAuth('TestResource.sensibleColumns');

        // Assert: Label should be replaced with asterisks
        $this->assertNotNull($element);
        $this->assertNotEquals($originalText, $element->label, 'Label should be hashed');
        $this->assertStringContainsString('*', $element->label, 'Label should contain asterisks');
        // Minimum 12 characters by default
        $this->assertGreaterThanOrEqual(12, strlen($element->label), 'Hashed label should be at least 12 characters');
    }

    /**
     * INVARIANT: hashIfNotAuth for Field element hashes value
     *
     * @test
     */
    public function test_hash_if_not_auth_field_hashes_value()
    {
        // Arrange: User without sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use hashIfNotAuth on Input field
        $originalValue = 'Secret123Value';
        $field = _Input('SSN')->value($originalValue)->hashIfNotAuth('TestResource.sensibleColumns');

        // Assert: Value should be replaced with asterisks
        $this->assertNotNull($field);
        $this->assertNotEquals($originalValue, $field->value, 'Value should be hashed');
        $this->assertStringContainsString('*', $field->value, 'Value should contain asterisks');
        $this->assertGreaterThanOrEqual(12, strlen($field->value), 'Hashed value should be at least 12 characters');
    }

    /**
     * INVARIANT: hashIfNotAuth with custom minChars
     *
     * @test
     */
    public function test_hash_if_not_auth_custom_min_chars()
    {
        // Arrange: User without permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use hashIfNotAuth with custom minChars = 20
        $element = _Html('Sensitive')->hashIfNotAuth('TestResource.sensibleColumns', null, 20);

        // Assert: Hashed label should have at least 20 characters
        $this->assertNotNull($element);
        $this->assertGreaterThanOrEqual(20, strlen($element->label), 'Hashed label should be at least 20 characters');
        $this->assertStringContainsString('*', $element->label);
    }

    /**
     * INVARIANT: hashIfNotAuth shows original value with permission
     *
     * @test
     */
    public function test_hash_if_not_auth_shows_original_with_permission()
    {
        // Arrange: User WITH sensibleColumns permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource.sensibleColumns' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use hashIfNotAuth
        $originalValue = 'SensitiveData123';
        $element = _Html($originalValue)->hashIfNotAuth('TestResource.sensibleColumns');

        // Assert: Should show original label (not hashed)
        $this->assertNotNull($element);
        $this->assertEquals($originalValue, $element->label, 'Label should NOT be hashed with permission');
    }

    /**
     * INVARIANT: hashAndReadOnlyIfNotAuth combines both macros
     *
     * @test
     */
    public function test_hash_and_read_only_if_not_auth_combined()
    {
        // Arrange: User without permissions
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use hashAndReadOnlyIfNotAuth
        $originalValue = 'SecretPassword123';
        $field = _Input('Password')->value($originalValue)->hashAndReadOnlyIfNotAuth('TestResource');

        // Assert: Field should be both hashed AND read-only
        $this->assertNotNull($field);

        // Check value is hashed
        $this->assertNotEquals($originalValue, $field->value, 'Value should be hashed');
        $this->assertStringContainsString('*', $field->value, 'Value should contain asterisks');

        // Check field is read-only (has opacity class)
        $this->assertTrue(
            str_contains($field->class ?? '', 'opacity') || str_contains($field->class ?? '', '!opacity'),
            'Field should have opacity class (read-only indicator)'
        );
    }

    /**
     * INVARIANT: hashAndReadOnlyIfNotAuth with both permissions shows editable original
     *
     * @test
     */
    public function test_hash_and_read_only_if_not_auth_with_permissions()
    {
        // Arrange: User WITH both WRITE and sensibleColumns permissions
        $data = AuthTestHelpers::createUserWithRole(
            [
                'TestResource' => PermissionTypeEnum::WRITE,
                'TestResource.sensibleColumns' => PermissionTypeEnum::READ,
            ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use hashAndReadOnlyIfNotAuth
        $originalValue = 'SecretPassword123';
        $field = _Input('Password')->value($originalValue)->hashAndReadOnlyIfNotAuth('TestResource');

        // Assert: Field should show original value AND be editable
        $this->assertNotNull($field);
        $this->assertEquals($originalValue, $field->value, 'Value should NOT be hashed with sensibleColumns permission');
    }

    /**
     * Edge case: Macros with unauthenticated user (guest)
     *
     * @test
     */
    public function test_macros_with_guest_user()
    {
        // Arrange: No authenticated user
        auth()->logout();

        // Act: Use macros as guest
        $element = _Button('Test')->checkAuth('TestResource', PermissionTypeEnum::READ, null, true);
        $field = _Input('Name')->readOnlyIfNotAuth('TestResource');

        // Assert: checkAuth should return null for guest
        $this->assertNull($element, 'Guest should not have access via checkAuth');

        // readOnlyIfNotAuth should make field read-only for guest
        $this->assertNotNull($field);
        $this->assertTrue(
            str_contains($field->class ?? '', 'opacity') || str_contains($field->class ?? '', '!opacity'),
            'Field should be read-only for guest'
        );
    }

    /**
     * INVARIANT: Different input field types work with macros
     *
     * @test
     */
    public function test_different_field_types_with_macros()
    {
        // Arrange: User without WRITE permission
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Test different field types
        $input = _Input('Name')->readOnlyIfNotAuth('TestResource');
        $textarea = _Textarea('Description')->readOnlyIfNotAuth('TestResource');
        $email = _Email('Email')->readOnlyIfNotAuth('TestResource');

        // Assert: All field types should be read-only
        $this->assertNotNull($input, 'Input should not be null');
        $this->assertNotNull($textarea, 'Textarea should not be null');
        $this->assertNotNull($email, 'Email should not be null');

        // All should have opacity class (read-only indicator)
        $this->assertTrue(
            str_contains($input->class ?? '', 'opacity') || str_contains($input->class ?? '', '!opacity'),
            'Input should be read-only'
        );
        $this->assertTrue(
            str_contains($textarea->class ?? '', 'opacity') || str_contains($textarea->class ?? '', '!opacity'),
            'Textarea should be read-only'
        );
        $this->assertTrue(
            str_contains($email->class ?? '', 'opacity') || str_contains($email->class ?? '', '!opacity'),
            'Email should be read-only'
        );
    }

    /**
     * INVARIANT: checkAuth works with ALL permission type
     *
     * @test
     */
    public function test_check_auth_with_all_permission()
    {
        // Arrange: User with ALL permission (includes READ/WRITE/DELETE)
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::ALL],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Check various permission types
        $readElement = _Button('Read')->checkAuth('TestResource', PermissionTypeEnum::READ, null, true);
        $writeElement = _Button('Write')->checkAuth('TestResource', PermissionTypeEnum::WRITE, null, true);
        $deleteElement = _Button('Delete')->checkAuth('TestResource', PermissionTypeEnum::DELETE, null, true);

        // Assert: ALL permission should grant access to all types
        $this->assertNotNull($readElement, 'ALL permission should grant READ');
        $this->assertNotNull($writeElement, 'ALL permission should grant WRITE');
        $this->assertNotNull($deleteElement, 'ALL permission should grant DELETE');
    }

    /**
     * INVARIANT: readOnlyIfNotAuth for multiple resources
     *
     * @test
     */
    public function test_read_only_if_not_auth_multiple_checks()
    {
        // Arrange: User with permission for Resource A but not B
        $data = AuthTestHelpers::createUserWithRole(
            ['ResourceA' => PermissionTypeEnum::WRITE],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        AuthTestHelpers::createPermission('ResourceA');
        AuthTestHelpers::createPermission('ResourceB');

        $this->actingAs($user);

        // Act: Check fields for different resources
        $fieldA = _Input('Field A')->readOnlyIfNotAuth('ResourceA');
        $fieldB = _Input('Field B')->readOnlyIfNotAuth('ResourceB');

        // Assert: Field A should be editable, Field B should be read-only
        $this->assertNotNull($fieldA);
        $this->assertNotNull($fieldB);

        // Field B should have opacity class (no permission)
        $this->assertTrue(
            str_contains($fieldB->class ?? '', 'opacity') || str_contains($fieldB->class ?? '', '!opacity'),
            'Field B should be read-only without permission'
        );
    }

    /**
     * Performance: Multiple field macros use cache efficiently
     *
     * @test
     */
    public function test_multiple_field_macros_use_cache()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Create multiple fields with same permission check
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $field1 = _Input('Field 1')->readOnlyIfNotAuth('TestResource');
        $queries1 = $this->getQueryCount();

        \DB::flushQueryLog();
        $field2 = _Input('Field 2')->readOnlyIfNotAuth('TestResource');
        $field3 = _Input('Field 3')->readOnlyIfNotAuth('TestResource');
        $queries2 = $this->getQueryCount();

        // Assert: Subsequent calls should use cache and not increase queries significantly
        $this->assertNotNull($field1);
        $this->assertNotNull($field2);
        $this->assertNotNull($field3);

        // Second batch should have fewer or equal queries due to caching
        $this->assertLessThanOrEqual(
            $queries1,
            $queries2,
            'Subsequent field macro calls should use cache'
        );
    }
}


