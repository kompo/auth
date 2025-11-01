<?php

namespace Kompo\Auth\Models\Plugins\Services;

/**
 * Factory for creating security services with proper dependency injection
 *
 * This factory handles the creation of all security services, ensuring proper
 * dependency injection and avoiding initialization issues.
 */
class SecurityServiceFactory
{
    protected $bypassService;
    protected $cacheService;

    public function __construct(
        SecurityBypassService $bypassService,
        PermissionCacheService $cacheService
    ) {
        $this->bypassService = $bypassService;
        $this->cacheService = $cacheService;
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

        // Create team service first (foundation service)
        $teamService = new TeamSecurityService($modelClassString, $this->cacheService);

        // Create field protection service
        $fieldProtectionService = new FieldProtectionService(
            $this->bypassService,
            $this->cacheService,
            $teamService
        );

        // Create batch permission service
        $batchPermissionService = new BatchPermissionService(
            $this->cacheService,
            $teamService,
            $fieldProtectionService
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
            'cache' => $this->cacheService,
            'team' => $teamService,
            'fieldProtection' => $fieldProtectionService,
            'batchPermission' => $batchPermissionService,
            'readSecurity' => $readSecurityService,
            'writeSecurity' => $writeSecurityService,
            'deleteSecurity' => $deleteSecurityService,
        ];
    }

    /**
     * Get the bypass service (singleton)
     */
    public function getBypassService(): SecurityBypassService
    {
        return $this->bypassService;
    }

    /**
     * Get the cache service (singleton)
     */
    public function getCacheService(): PermissionCacheService
    {
        return $this->cacheService;
    }
}
