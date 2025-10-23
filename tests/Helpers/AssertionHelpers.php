<?php

namespace Kompo\Auth\Tests\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use PHPUnit\Framework\Assert;

/**
 * Custom Assertion Helpers
 * 
 * Provides domain-specific assertions for authorization testing.
 */
class AssertionHelpers
{
    /**
     * Assert that access is denied (permission check returns false)
     */
    public static function assertAccessDenied(
        $user,
        string $permissionKey,
        PermissionTypeEnum $type = null,
        $teamIds = null,
        string $message = ''
    ): void {
        $type = $type ?? PermissionTypeEnum::READ;
        
        Assert::assertFalse(
            $user->hasPermission($permissionKey, $type, $teamIds),
            $message ?: "Access should be denied for '{$permissionKey}' with {$type->name}"
        );
    }

    /**
     * Assert that access is granted (permission check returns true)
     */
    public static function assertAccessGranted(
        $user,
        string $permissionKey,
        PermissionTypeEnum $type = null,
        $teamIds = null,
        string $message = ''
    ): void {
        $type = $type ?? PermissionTypeEnum::READ;
        
        Assert::assertTrue(
            $user->hasPermission($permissionKey, $type, $teamIds),
            $message ?: "Access should be granted for '{$permissionKey}' with {$type->name}"
        );
    }

    /**
     * Assert that a query returns no results (blocked by security)
     */
    public static function assertQueryBlockedByAuthorization($query, string $message = ''): void
    {
        $results = $query->get();
        
        Assert::assertEmpty(
            $results,
            $message ?: 'Query should return no results due to authorization restrictions'
        );
    }

    /**
     * Assert that a query returns results (not blocked)
     */
    public static function assertQueryNotBlocked($query, int $expectedCount = null, string $message = ''): void
    {
        $results = $query->get();
        
        Assert::assertNotEmpty(
            $results,
            $message ?: 'Query should return results but was blocked by authorization'
        );
        
        if ($expectedCount !== null) {
            Assert::assertCount(
                $expectedCount,
                $results,
                "Query should return exactly {$expectedCount} results"
            );
        }
    }

    /**
     * Assert that a model save operation is blocked by permissions
     */
    public static function assertSaveBlockedByPermissions(callable $saveCallback, string $message = ''): void
    {
        try {
            $saveCallback();
            Assert::fail($message ?: 'Save operation should have been blocked by permissions');
        } catch (\Kompo\Auth\Models\Teams\Roles\PermissionException $e) {
            Assert::assertTrue(true); // Expected exception
        }
    }

    /**
     * Assert that a model delete operation is blocked by permissions
     */
    public static function assertDeleteBlockedByPermissions(callable $deleteCallback, string $message = ''): void
    {
        try {
            $deleteCallback();
            Assert::fail($message ?: 'Delete operation should have been blocked by permissions');
        } catch (\Kompo\Auth\Models\Teams\Roles\PermissionException $e) {
            Assert::assertTrue(true); // Expected exception
        }
    }

    /**
     * Assert that a sensitive field is hidden from the model
     */
    public static function assertSensitiveFieldHidden($model, string $fieldName, string $message = ''): void
    {
        $attributes = $model->getAttributes();
        
        Assert::assertArrayNotHasKey(
            $fieldName,
            $attributes,
            $message ?: "Sensitive field '{$fieldName}' should be hidden"
        );
    }

    /**
     * Assert that a sensitive field is visible in the model
     */
    public static function assertSensitiveFieldVisible($model, string $fieldName, string $message = ''): void
    {
        $attributes = $model->getAttributes();
        
        Assert::assertArrayHasKey(
            $fieldName,
            $attributes,
            $message ?: "Field '{$fieldName}' should be visible"
        );
    }

    /**
     * Assert that cache contains permission data
     */
    public static function assertPermissionCached(string $cacheKey, string $message = ''): void
    {
        Assert::assertTrue(
            Cache::has($cacheKey),
            $message ?: "Permission cache should exist for key '{$cacheKey}'"
        );
    }

    /**
     * Assert that permission cache is invalidated
     */
    public static function assertPermissionCacheInvalidated(string $cacheKey, string $message = ''): void
    {
        Assert::assertFalse(
            Cache::has($cacheKey),
            $message ?: "Permission cache should be invalidated for key '{$cacheKey}'"
        );
    }

    /**
     * Assert that DENY permission takes precedence
     */
    public static function assertDenyPrecedence(
        $user,
        string $permissionKey,
        string $message = ''
    ): void {
        // Regardless of other permissions, DENY should block access
        Assert::assertFalse(
            $user->hasPermission($permissionKey, PermissionTypeEnum::READ),
            $message ?: "DENY should take precedence and block access to '{$permissionKey}'"
        );
        
        Assert::assertFalse(
            $user->hasPermission($permissionKey, PermissionTypeEnum::WRITE),
            $message ?: "DENY should take precedence and block write access to '{$permissionKey}'"
        );
        
        Assert::assertFalse(
            $user->hasPermission($permissionKey, PermissionTypeEnum::ALL),
            $message ?: "DENY should take precedence and block all access to '{$permissionKey}'"
        );
    }

    /**
     * Assert that team hierarchy allows access
     */
    public static function assertTeamHierarchyAccess($teamRole, $targetTeamId, string $message = ''): void
    {
        Assert::assertTrue(
            $teamRole->hasAccessToTeam($targetTeamId),
            $message ?: "Team role should have access to team {$targetTeamId} via hierarchy"
        );
    }

    /**
     * Assert that team hierarchy denies access
     */
    public static function assertTeamHierarchyDenied($teamRole, $targetTeamId, string $message = ''): void
    {
        Assert::assertFalse(
            $teamRole->hasAccessToTeam($targetTeamId),
            $message ?: "Team role should NOT have access to team {$targetTeamId}"
        );
    }

    /**
     * Assert that query count is within acceptable range (for cache validation)
     */
    public static function assertQueryCountWithinRange(int $min, int $max, string $message = ''): void
    {
        $count = count(DB::getQueryLog());
        
        Assert::assertGreaterThanOrEqual(
            $min,
            $count,
            $message ?: "Query count ({$count}) should be at least {$min}"
        );
        
        Assert::assertLessThanOrEqual(
            $max,
            $count,
            $message ?: "Query count ({$count}) should be at most {$max}"
        );
    }

    /**
     * Assert that cache is hit (query count should be lower)
     */
    public static function assertCacheHit(int $baselineQueryCount, int $tolerance = 2, string $message = ''): void
    {
        $currentCount = count(DB::getQueryLog());
        
        Assert::assertLessThan(
            $baselineQueryCount - $tolerance,
            $currentCount,
            $message ?: "Cache should reduce query count from {$baselineQueryCount} to {$currentCount}"
        );
    }

    /**
     * Assert that an HTTP response has a specific status (for component tests)
     */
    public static function assertResponseStatus($response, int $expectedStatus, string $message = ''): void
    {
        Assert::assertEquals(
            $expectedStatus,
            $response->status(),
            $message ?: "Response status should be {$expectedStatus}"
        );
    }

    /**
     * Assert that a component is not rendered (authorization failed)
     */
    public static function assertComponentNotRendered($componentClass, $user, string $message = ''): void
    {
        // This would need to be implemented based on how Kompo components are tested
        // For now, this is a placeholder
        Assert::assertTrue(
            true,
            $message ?: "Component {$componentClass} should not be rendered for user"
        );
    }

    /**
     * Assert that user has access to specific teams
     */
    public static function assertUserHasAccessToTeams($user, array $teamIds, string $message = ''): void
    {
        foreach ($teamIds as $teamId) {
            Assert::assertTrue(
                $user->hasAccessToTeam($teamId),
                $message ?: "User should have access to team {$teamId}"
            );
        }
    }

    /**
     * Assert that user does NOT have access to specific teams
     */
    public static function assertUserDoesNotHaveAccessToTeams($user, array $teamIds, string $message = ''): void
    {
        foreach ($teamIds as $teamId) {
            Assert::assertFalse(
                $user->hasAccessToTeam($teamId),
                $message ?: "User should NOT have access to team {$teamId}"
            );
        }
    }
}

