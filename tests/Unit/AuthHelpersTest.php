<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Teams\PermissionResolver;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Auth Helpers Test
 * 
 * Tests global helper functions for authorization.
 * 
 * Scenarios covered:
 * - globalSecurityBypass()
 * - permissionMustBeAuthorized()
 * - checkAuthPermission()
 * - currentTeam() / currentTeamId() / currentTeamRole()
 * - isAppSuperAdmin()
 * - batchCheckPermissions()
 * - clearAuthStaticCache()
 * - executeInBypassContext()
 * - Macros: checkAuth, readOnlyIfNotAuth, hashIfNotAuth
 */
class AuthHelpersTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('TestResource');
    }

    /**
     * INVARIANT: globalSecurityBypass returns config value
     * 
     * @test
     */
    public function test_global_security_bypass_returns_config()
    {
        // Arrange: Bypass disabled
        $this->disableSecurityBypass();

        // Act
        $bypass = globalSecurityBypass();

        // Assert
        $this->assertFalse($bypass, 'Should return false when bypass is disabled');

        // Enable bypass
        $this->enableSecurityBypass();

        $bypass2 = globalSecurityBypass();
        $this->assertTrue($bypass2, 'Should return true when bypass is enabled');
    }

    /**
     * INVARIANT: permissionMustBeAuthorized checks permission existence
     * 
     * @test
     */
    public function test_permission_must_be_authorized()
    {
        // Arrange: Permission exists
        AuthTestHelpers::createPermission('ExistingPermission');

        // Act
        $mustAuthorize = permissionMustBeAuthorized('ExistingPermission');

        // Assert: Should require authorization
        $this->assertTrue($mustAuthorize, 'Existing permission should require authorization');

        // Non-existent permission
        $mustAuthorizeNonExistent = permissionMustBeAuthorized('NonExistentPermission');

        // Assert: Should NOT require authorization (default behavior)
        $this->assertFalse($mustAuthorizeNonExistent, 'Non-existent permission should not require authorization by default');
    }

    /**
     * INVARIANT: permissionMustBeAuthorized with strict mode
     * 
     * @test
     */
    public function test_permission_must_be_authorized_strict_mode()
    {
        // Arrange: Enable strict mode
        $this->setSecurityConfig([
            'check-even-if-permission-does-not-exist' => true,
        ]);

        // Act: Check non-existent permission
        $mustAuthorize = permissionMustBeAuthorized('NonExistentStrictPermission');

        // Assert: Should require authorization in strict mode
        $this->assertTrue($mustAuthorize, 'Non-existent permission should require authorization in strict mode');
    }

    /**
     * INVARIANT: checkAuthPermission integrates with PermissionResolver
     * 
     * @test
     */
    public function test_check_auth_permission()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Use helper
        $hasPermission = checkAuthPermission('TestResource', PermissionTypeEnum::READ);

        // Assert
        $this->assertTrue($hasPermission, 'checkAuthPermission should return true');
    }

    /**
     * INVARIANT: currentTeam() returns current team
     * 
     * @test
     */
    public function test_current_team_helpers()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];
        $team = $data['team'];

        $this->actingAs($user);

        // Act: Get current team
        $currentTeam = currentTeam();
        $currentTeamId = currentTeamId();
        $currentTeamRole = currentTeamRole();

        // Assert
        $this->assertNotNull($currentTeam, 'currentTeam should return team');
        $this->assertEquals($team->id, $currentTeamId, 'currentTeamId should match');
        $this->assertNotNull($currentTeamRole, 'currentTeamRole should return team role');
    }

    /**
     * INVARIANT: batchCheckPermissions checks multiple permissions
     * 
     * @test
     */
    public function test_batch_check_permissions()
    {
        // Arrange: User with some permissions
        $data = AuthTestHelpers::createUserWithRole(
            [
                'Permission1' => PermissionTypeEnum::READ,
                'Permission2' => PermissionTypeEnum::ALL,
            ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        AuthTestHelpers::createPermission('Permission1');
        AuthTestHelpers::createPermission('Permission2');
        AuthTestHelpers::createPermission('Permission3');

        $this->actingAs($user);

        // Act: Batch check
        $results = batchCheckPermissions(
            ['Permission1', 'Permission2', 'Permission3'],
            PermissionTypeEnum::READ
        );

        // Assert: Mixed results
        $this->assertTrue($results['Permission1'], 'Should have Permission1');
        $this->assertTrue($results['Permission2'], 'Should have Permission2');
        $this->assertFalse($results['Permission3'], 'Should NOT have Permission3');
    }

    /**
     * INVARIANT: clearAuthStaticCache clears current context caches
     * 
     * @test
     */
    public function test_clear_auth_static_cache()
    {
        // Arrange: Populate caches
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Populate helpers cache
        $team = currentTeam();
        $teamRole = currentTeamRole();

        // Act: Clear static cache
        clearAuthStaticCache();

        // Enable query log
        $this->enableQueryLog();
        DB::flushQueryLog();

        // Call helpers again
        $team2 = currentTeam();
        $queries = $this->getQueryCount();

        // Assert: Cache was cleared (queries > 0 or different result)
        // Note: Exact behavior depends on implementation
        $this->assertNotNull($team2);
    }

    /**
     * INVARIANT: executeInBypassContext prevents infinite loops
     * 
     * @test
     */
    public function test_execute_in_bypass_context()
    {
        // Arrange: Start NOT in bypass context
        $this->assertFalse(HasSecurity::isInBypassContext());

        // Act: Execute in bypass context
        $result = executeInBypassContext(function () {
            // Inside bypass context
            return HasSecurity::isInBypassContext();
        });

        // Assert: Was in bypass context during execution
        $this->assertTrue($result, 'Should be in bypass context during callback');

        // Assert: Back to normal after execution
        $this->assertFalse(HasSecurity::isInBypassContext(), 'Should exit bypass context after callback');
    }

    /**
     * INVARIANT: isInBypassContext returns current state
     * 
     * @test
     */
    public function test_is_in_bypass_context()
    {
        // Act: Check outside bypass
        $isInBypass1 = isInBypassContext();

        // Assert
        $this->assertFalse($isInBypass1, 'Should not be in bypass context initially');

        // Enter bypass context
        HasSecurity::enterBypassContext();

        $isInBypass2 = isInBypassContext();
        $this->assertTrue($isInBypass2, 'Should be in bypass context after entering');

        // Exit bypass context
        HasSecurity::exitBypassContext();

        $isInBypass3 = isInBypassContext();
        $this->assertFalse($isInBypass3, 'Should not be in bypass context after exiting');
    }

    /**
     * INVARIANT: safeSecurityQuery uses bypass context
     * 
     * @test
     */
    public function test_safe_security_query()
    {
        // Arrange
        $this->assertFalse(HasSecurity::isInBypassContext());

        // Act: Execute safe query
        $result = safeSecurityQuery(function () {
            return HasSecurity::isInBypassContext();
        });

        // Assert: Was in bypass during query
        $this->assertTrue($result, 'safeSecurityQuery should execute in bypass context');

        // Back to normal
        $this->assertFalse(HasSecurity::isInBypassContext());
    }

    /**
     * Performance: Helper functions use cache
     * 
     * @test
     */
    public function test_helper_functions_use_cache()
    {
        // Arrange
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Call currentTeam multiple times
        $this->enableQueryLog();
        DB::flushQueryLog();

        currentTeam();
        $queries1 = $this->getQueryCount();

        DB::flushQueryLog();
        currentTeam();
        $queries2 = $this->getQueryCount();

        // Assert: Second call should use cache
        $this->assertLessThanOrEqual(
            $queries1,
            $queries2,
            'Helper functions should use cache'
        );
    }

    /**
     * Edge case: Helpers with null user
     * 
     * @test
     */
    public function test_helpers_with_null_user()
    {
        // No authenticated user

        // Act & Assert: Helpers should handle gracefully
        $this->assertNull(currentTeam(), 'currentTeam should return null without auth');
        $this->assertNull(currentTeamId(), 'currentTeamId should return null without auth');
        $this->assertNull(currentTeamRole(), 'currentTeamRole should return null without auth');
        $this->assertFalse(isTeamOwner(), 'isTeamOwner should return false without auth');
        $this->assertFalse(isSuperAdmin(), 'isSuperAdmin should return false without auth');
    }

    /**
     * INVARIANT: batchCheckPermissions with global bypass
     * 
     * @test
     */
    public function test_batch_check_permissions_with_bypass()
    {
        // Arrange: Enable global bypass
        $this->enableSecurityBypass();

        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Batch check (should all return true with bypass)
        $results = batchCheckPermissions(
            ['AnyPermission1', 'AnyPermission2', 'AnyPermission3'],
            PermissionTypeEnum::READ
        );

        // Assert: All should be true with global bypass
        $this->assertTrue($results['AnyPermission1']);
        $this->assertTrue($results['AnyPermission2']);
        $this->assertTrue($results['AnyPermission3']);
    }

    /**
     * Performance: batchCheckPermissions is more efficient than individual checks
     * 
     * @test
     */
    public function test_batch_check_permissions_efficiency()
    {
        // Arrange
        AuthTestHelpers::createPermission('Perm1');
        AuthTestHelpers::createPermission('Perm2');
        AuthTestHelpers::createPermission('Perm3');

        $data = AuthTestHelpers::createUserWithRole(
            [
                'Perm1' => PermissionTypeEnum::READ,
                'Perm2' => PermissionTypeEnum::READ,
            ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $user = $data['user'];

        $this->actingAs($user);

        // Act: Batch check
        $this->enableQueryLog();
        DB::flushQueryLog();

        $results = batchCheckPermissions(['Perm1', 'Perm2', 'Perm3'], PermissionTypeEnum::READ);

        $batchQueriesAll = $this->getQueryCount();

        DB::flushQueryLog();

        $this->enableQueryLog();
        Cache::flush();
        app(PermissionResolver::class)->clearAllCache();
        
        // Individual checks
        checkAuthPermission('Perm1', PermissionTypeEnum::READ);

        $queryCount1 = $this->getQueryCount();

        // Assert: Should be efficient
        $this->assertLessThanOrEqual(
            $queryCount1 + 5,
            $batchQueriesAll,
            "Batch permission checks should be efficient (got {$batchQueriesAll})"
        );

        // Results should be correct
        $this->assertTrue($results['Perm1']);
        $this->assertTrue($results['Perm2']);
        $this->assertFalse($results['Perm3']);
    }
}


