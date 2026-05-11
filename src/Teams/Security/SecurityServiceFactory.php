<?php

namespace Kompo\Auth\Teams\Security;

use Kompo\Auth\Teams\Cache\CachedFieldProtectionService;
use Kompo\Auth\Teams\Cache\CachedTeamSecurityService;
use Kompo\Auth\Teams\Contracts\PermissionResolverInterface;
use Kompo\Auth\Teams\Security\Contracts\FieldProtectionServiceInterface;
use Kompo\Auth\Teams\Security\Contracts\TeamSecurityServiceInterface;

/**
 * Factory for creating security services with proper dependency injection.
 *
 * Two service flavors:
 *   - Singletons (bypass, team, fieldProtection, batchPermission,
 *     owned-records): resolved straight from the container via
 *     `app(Interface::class)`. Methods take the relevant model/class at call
 *     time so a single shared instance covers every model class.
 *   - Model-class-bound (read, write, delete): need `$modelClass` at
 *     construction because they register event listeners on a specific class
 *     (`addGlobalScope`, `saving`, `deleting`). Resolved via
 *     `app()->makeWith()` so the container auto-resolves the rest of the deps.
 */
class SecurityServiceFactory
{
    public function __construct(
        protected SecurityBypassService $bypassService,
    ) {}

    /**
     * Create all services for a specific model class.
     *
     * @param  string|object  $modelClass
     * @return array
     */
    public function createServicesForModel($modelClass): array
    {
        $modelClassString = is_object($modelClass) ? get_class($modelClass) : $modelClass;

        $teamService   = $this->createTeamSecurityServiceForModel($modelClassString);
        $writeSecurity = app()->makeWith(WriteSecurityService::class, [
            'modelClass'  => $modelClassString,
            'teamService' => $teamService,
        ]);

        return [
            'bypass'           => $this->bypassService,
            'team'             => $teamService,
            'fieldProtection'  => $this->createFieldProtectionService(),
            'batchPermission'  => $this->createBatchPermissionServiceForModel($modelClassString),
            'readSecurity'     => app()->makeWith(ReadSecurityService::class, [
                'modelClass'  => $modelClassString,
                'teamService' => $teamService,
            ]),
            'writeSecurity'    => $writeSecurity,
            'deleteSecurity'   => app()->makeWith(DeleteSecurityService::class, [
                'modelClass'   => $modelClassString,
                'teamService'  => $teamService,
                'writeService' => $writeSecurity,
            ]),
        ];
    }

    /**
     * Singleton bound in KompoAuthServiceProvider when auth's register() runs.
     * The fallback covers the edge case where a consumer provider registers
     * before auth and triggers a model boot via HasSecurity — auto-discovery
     * is alphabetical, so e.g. `Condoedge\Crm` registers before `Kompo\Auth`.
     */
    public function createFieldProtectionService(): FieldProtectionServiceInterface
    {
        if (app()->bound(FieldProtectionServiceInterface::class)) {
            return app(FieldProtectionServiceInterface::class);
        }

        return new CachedFieldProtectionService(
            new FieldProtectionService($this->bypassService, $this->createTeamSecurityServiceForModel('')),
        );
    }

    /**
     * Singleton bound in KompoAuthServiceProvider. The model class parameter
     * is preserved for API compatibility but no longer affects construction —
     * BatchPermissionService methods take models at call time.
     */
    public function createBatchPermissionServiceForModel(string $modelClass): BatchPermissionService
    {
        if (app()->bound(BatchPermissionService::class)) {
            return app(BatchPermissionService::class);
        }

        return new BatchPermissionService(
            app(PermissionResolverInterface::class),
            $this->createTeamSecurityServiceForModel($modelClass),
            $this->createFieldProtectionService(),
            $this->bypassService,
        );
    }

    /**
     * Singleton bound in KompoAuthServiceProvider. The model class parameter
     * is preserved for API compatibility but no longer affects construction —
     * every method on the service takes the relevant class/model at call time.
     */
    public function createTeamSecurityServiceForModel(string $modelClass): TeamSecurityServiceInterface
    {
        if (app()->bound(TeamSecurityServiceInterface::class)) {
            return app(TeamSecurityServiceInterface::class);
        }

        return new CachedTeamSecurityService(new TeamSecurityService());
    }

    public function getBypassService(): SecurityBypassService
    {
        return $this->bypassService;
    }
}
