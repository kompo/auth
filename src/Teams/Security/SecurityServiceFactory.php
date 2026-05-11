<?php

namespace Kompo\Auth\Teams\Security;

use Kompo\Auth\Teams\Cache\CachedFieldProtectionService;
use Kompo\Auth\Teams\Cache\CachedTeamSecurityService;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\Security\Contracts\FieldProtectionServiceInterface;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;

/**
 * Factory for creating security services with proper dependency injection
 *
 * This factory handles the creation of all security services, ensuring proper
 * dependency injection and avoiding initialization issues.
 *
 * Layering note (Phases 3 & 4):
 *   - TeamSecurityService / FieldProtectionService are pure compute layers.
 *   - CachedTeamSecurityService / CachedFieldProtectionService are per-request decorators.
 *   - The factory returns the decorators everywhere callers expect those services,
 *     so callers typehint the interface and stay agnostic about caching.
 */
class SecurityServiceFactory
{
    protected $bypassService;
    protected $permissionResolver;

    public function __construct(
        SecurityBypassService $bypassService,
        PermissionResolverInterface $permissionResolver
    ) {
        $this->bypassService = $bypassService;
        $this->permissionResolver = $permissionResolver;
    }

    /**
     * Create all services for a specific model class
     *
     * @param string|object $modelClass The model class or instance
     * @return array Array of initialized services
     */
    public function createServicesForModel($modelClass): array
    {
        // Normalize modelClass to string
        $modelClassString = is_object($modelClass) ? get_class($modelClass) : $modelClass;

        // Create team service first (foundation service) — returns the cached decorator
        $teamService = $this->createTeamSecurityServiceForModel($modelClassString);

        // Field protection service is constructed via createFieldProtectionService
        // so the cache decorator is always applied consistently.
        $fieldProtectionService = $this->createFieldProtectionService($modelClassString, $teamService);

        // Create batch permission service — depends on the cached resolver,
        // not on the legacy PermissionCacheService.
        $batchPermissionService = new BatchPermissionService(
            $this->permissionResolver,
            $teamService,
            $fieldProtectionService,
            $this->bypassService
        );

        // Create read security service
        $readSecurityService = new ReadSecurityService(
            $modelClassString,
            $this->bypassService,
            $teamService
        );

        // Create write security service
        $writeSecurityService = new WriteSecurityService(
            $modelClassString,
            $this->bypassService,
            $teamService
        );

        // Create delete security service
        $deleteSecurityService = new DeleteSecurityService(
            $modelClassString,
            $this->bypassService,
            $teamService,
            $writeSecurityService
        );

        return [
            'bypass' => $this->bypassService,
            'team' => $teamService,
            'fieldProtection' => $fieldProtectionService,
            'batchPermission' => $batchPermissionService,
            'readSecurity' => $readSecurityService,
            'writeSecurity' => $writeSecurityService,
            'deleteSecurity' => $deleteSecurityService,
        ];
    }

    /**
     * Returns the cached decorator. Callers should typehint
     * FieldProtectionServiceInterface, not the concrete inner class.
     */
    public function createFieldProtectionService(string $modelClass, $teamService = null): FieldProtectionServiceInterface
    {
        return new CachedFieldProtectionService(
            new FieldProtectionService(
                $this->bypassService,
                $teamService ?? $this->createTeamSecurityServiceForModel($modelClass)
            )
        );
    }

    public function createBatchPermissionServiceForModel(string $modelClass): BatchPermissionService
    {
        $teamService = $this->createTeamSecurityServiceForModel($modelClass);

        return new BatchPermissionService(
            $this->permissionResolver,
            $teamService,
            $this->createFieldProtectionService($modelClass, $teamService),
            $this->bypassService
        );
    }

    /**
     * Returns the cached decorator. Callers should typehint
     * TeamSecurityServiceInterface, not the concrete inner class.
     */
    public function createTeamSecurityServiceForModel(string $modelClass): TeamSecurityServiceInterface
    {
        return new CachedTeamSecurityService(
            new TeamSecurityService($modelClass),
            $modelClass,
        );
    }

    /**
     * Get the bypass service (singleton)
     */
    public function getBypassService(): SecurityBypassService
    {
        return $this->bypassService;
    }
}
