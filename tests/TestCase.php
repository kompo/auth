<?php

namespace Kompo\Auth\Tests;

use Condoedge\Utils\CondoedgeUtilsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\KompoAuthServiceProvider;
use Kompo\KompoServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base Test Case for Kompo Auth Package
 * 
 * Provides common setup, helpers, and assertions for authorization tests.
 * 
 * Replicates the pattern from finance package tests.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Setup the test environment.
     */
    public function setUp(): void
    {
        parent::setUp();

        // Bind team model for the application (before provider boot)
        $this->app->bind('team-model', function () {
            return config('kompo-auth.team-model-namespace');
        });

        // Register and boot service providers (finance pattern)
        foreach ($this->serviceProviders($this->app) as $class) {
            $provider = new $class($this->app);
            $provider->register();  // Register first
            $provider->boot();      // Then boot
        }

        // Set up default configuration for tests
        $this->setDefaultTestConfig();

        // Clear all caches before each test
        Cache::flush();
    }

    /**
     * Get package service providers (finance pattern)
     */
    protected function serviceProviders($app): array
    {
        return [
            KompoServiceProvider::class,
            CondoedgeUtilsServiceProvider::class,
            KompoAuthServiceProvider::class,
        ];
    }

    /**
     * Set default test configuration
     */
    protected function setDefaultTestConfig(): void
    {
        Config::set('kompo-auth.security.bypass-security', false);
        Config::set('kompo-auth.security.default-read-security-restrictions', true);
        Config::set('kompo-auth.security.default-save-security-restrictions', true);
        Config::set('kompo-auth.security.default-delete-security-restrictions', true);
        Config::set('kompo-auth.security.default-restrict-by-team', true);
        Config::set('kompo-auth.security.check-even-if-permission-does-not-exist', false);
        Config::set('kompo-auth.superadmin-emails', []);
        Config::set('kompo-auth.cache.ttl', 900);
        Config::set('kompo-auth.cache.tags_enabled', true);
        Config::set('kompo-auth.security.lazy-protected-fields', true);

        // Overriding the bypass function because by default it bypass when running in console
        $this->app->singleton('kompo-auth.security-bypass', function ($app) {
            return function () {
                if (session()->isStarted() && config('kompo-auth.security.dont-check-if-not-logged-in', false) && !auth()->check()) {
                    return true;
                }

                if (auth()->user()?->isSuperAdmin()) {
                    return true;
                }

                if (isInBypassContext()) {
                    return true;
                }

                if (routeIsByPassed()) {
                    return true;
                }

                return config('kompo-auth.security.bypass-security', false);
            };
        });
    }

    /**
     * Override security configuration for specific tests
     */
    protected function setSecurityConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            Config::set("kompo-auth.security.{$key}", $value);
        }
    }

    /**
     * Enable global security bypass
     */
    protected function enableSecurityBypass(): void
    {
        Config::set('kompo-auth.security.bypass-security', true);
    }

    /**
     * Disable global security bypass
     */
    protected function disableSecurityBypass(): void
    {
        Config::set('kompo-auth.security.bypass-security', false);
    }

    /**
     * Clear all permission caches
     */
    protected function clearPermissionCache(): void
    {
        Cache::tags(['permissions-v2'])->flush();
        Cache::tags(['permissions'])->flush();
    }

    /**
     * Create authenticated user and set as current
     */
    protected function actingAsUser($user = null)
    {
        if (!$user) {
            $user = UserFactory::new()->create();
        }

        return $this->actingAs($user);
    }

    /**
     * Assert that a user has permission
     */
    protected function assertUserHasPermission($user, string $permissionKey, $type = null, $teamIds = null): void
    {
        $type = $type ?? \Kompo\Auth\Models\Teams\PermissionTypeEnum::READ;
        
        $this->assertTrue(
            $user->hasPermission($permissionKey, $type, $teamIds),
            "User does not have {$type->name} permission for '{$permissionKey}'"
        );
    }

    /**
     * Assert that a user does not have permission
     */
    protected function assertUserDoesNotHavePermission($user, string $permissionKey, $type = null, $teamIds = null): void
    {
        $type = $type ?? \Kompo\Auth\Models\Teams\PermissionTypeEnum::READ;
        
        $this->assertFalse(
            $user->hasPermission($permissionKey, $type, $teamIds),
            "User has {$type->name} permission for '{$permissionKey}' but should not"
        );
    }

    /**
     * Assert that a query returns empty results (blocked by security)
     */
    protected function assertQueryBlocked($query): void
    {
        $this->assertEmpty(
            $query->get(),
            'Query returned results but should have been blocked by security'
        );
    }

    /**
     * Assert that a query returns results (not blocked)
     */
    protected function assertQueryAllowed($query): void
    {
        $this->assertNotEmpty(
            $query->get(),
            'Query returned no results but should have been allowed by security'
        );
    }

    /**
     * Assert that saving a model throws PermissionException
     */
    protected function assertSaveBlocked(callable $callback): void
    {
        $this->expectException(\Kompo\Auth\Models\Teams\Roles\PermissionException::class);
        $callback();
    }

    /**
     * Assert that deleting a model throws PermissionException
     */
    protected function assertDeleteBlocked(callable $callback): void
    {
        $this->expectException(\Kompo\Auth\Models\Teams\Roles\PermissionException::class);
        $callback();
    }

    /**
     * Assert that a model has a specific attribute value
     */
    protected function assertModelHasAttribute($model, string $attribute, $expectedValue = null): void
    {
        $this->assertTrue(
            isset($model->{$attribute}),
            "Model does not have attribute '{$attribute}'"
        );

        if ($expectedValue !== null) {
            $this->assertEquals(
                $expectedValue,
                $model->{$attribute},
                "Attribute '{$attribute}' has unexpected value"
            );
        }
    }

    /**
     * Assert that a model does not have a specific attribute (field protection)
     */
    protected function assertModelDoesNotHaveAttribute($model, string $attribute): void
    {
        $this->assertFalse(
            isset($model->{$attribute}),
            "Model has attribute '{$attribute}' but should not (field protection failed)"
        );
    }

    /**
     * Assert that cache contains a specific key
     */
    protected function assertCacheHas(string $key): void
    {
        $this->assertTrue(
            Cache::has($key),
            "Cache does not contain key '{$key}'"
        );
    }

    /**
     * Assert that cache does not contain a specific key
     */
    protected function assertCacheDoesNotHave(string $key): void
    {
        $this->assertFalse(
            Cache::has($key),
            "Cache contains key '{$key}' but should not"
        );
    }

    /**
     * Get query count (for performance assertions)
     */
    protected function getQueryCount(): int
    {
        return count(\DB::getQueryLog());
    }

    /**
     * Enable query logging
     */
    protected function enableQueryLog(): void
    {
        \DB::enableQueryLog();
    }

    /**
     * Disable query logging
     */
    protected function disableQueryLog(): void
    {
        \DB::disableQueryLog();
    }

    /**
     * Assert that query count is less than or equal to expected
     */
    protected function assertQueryCountLessThanOrEqual(int $expected): void
    {
        $actual = $this->getQueryCount();
        $this->assertLessThanOrEqual(
            $expected,
            $actual,
            "Query count ({$actual}) exceeds expected maximum ({$expected})"
        );
    }

    /**
     * Teardown the test environment.
     */
    public function tearDown(): void
    {
        // Clear caches after each test
        Cache::flush();

        // Clear query log if enabled
        if (\DB::logging()) {
            \DB::disableQueryLog();
        }

        parent::tearDown();
    }
}

