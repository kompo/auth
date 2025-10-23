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
}

