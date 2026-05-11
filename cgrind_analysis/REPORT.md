# Cachegrind analysis — security layer

Source: `C:/wamp64/tmp/trace.sisc_local.1778445751.59068.cgrind` (540 MB)

**Total trace cost:** 2541799000 (Time_10ns units) = 25.42s wall time equivalent

**Total functions in trace:** 6434  
**Functions matching security filters:** 381

## Top 30 functions by SELF cost (security layer)

| # | % total | Self cost | Calls | Function |
|---:|---:|---:|---:|---|
| 1 | 1.34% | 339.8ms | 8,165 | `Kompo\Auth\Teams\Security\SecurityMetadataRegistry::for` |
| 2 | 0.54% | 137.2ms | 14,454 | `Kompo\Auth\Models\Plugins\HasSecurity->getAttribute` |
| 3 | 0.37% | 94.2ms | 1,584 | `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getBatchAncestorTeamIds` |
| 4 | 0.26% | 65.4ms | 4,889 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->remember` |
| 5 | 0.15% | 38.4ms | 2,109 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->cacheRememberWithTags` |
| 6 | 0.14% | 34.8ms | 1,584 | `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getAncestorTeamsUntilLevel` |
| 7 | 0.08% | 19.2ms | 1,584 | `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getDescendantTeamIds` |
| 8 | 0.05% | 13.6ms | 2,088 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->ttlForTag` |
| 9 | 0.03% | 7.9ms | 37 | `Kompo\Auth\Teams\Security\SecurityMetadataRegistry::compute` |
| 10 | 0.03% | 6.4ms | 1,584 | `Kompo\Auth\Teams\CacheKeyBuilder::teamDescendants` |
| 11 | 0.02% | 5.9ms | 37 | `Kompo\Auth\Teams\Security\ReadSecurityService->setupReadSecurity` |
| 12 | 0.02% | 5.9ms | 1 | `require_once::C:\Users\jkend\Documents\Projects\kompo\auth\src\Helpers\auth.php` |
| 13 | 0.02% | 5.4ms | 201 | `Kompo\Auth\KompoAuthServiceProvider->Kompo\Auth\{closure:C:\Users\jkend\Documents\Projects\kompo\auth\src\KompoAuthServiceProvider.php:192-197}` |
| 14 | 0.02% | 5.3ms | 201 | `globalSecurityBypass` |
| 15 | 0.02% | 4.8ms | 16 | `Kompo\Auth\Teams\Security\BatchPermissionService->batchProcessGroup` |
| 16 | 0.02% | 4.3ms | 2,144 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->trackKeyForTag` |
| 17 | 0.02% | 3.9ms | 45 | `Kompo\Auth\Teams\Security\SecurityServiceFactory->createTeamSecurityServiceForModel` |
| 18 | 0.01% | 3.3ms | 37 | `Kompo\Auth\Models\Plugins\HasSecurity->onBoot` |
| 19 | 0.01% | 2.5ms | 37 | `Kompo\Auth\Teams\Security\SecurityMetadataRegistry::checkLazyProtectedFields` |
| 20 | 0.01% | 2.2ms | 16 | `Kompo\Auth\Teams\Cache\CachedTeamSecurityService->getTeamOwnersIdsSafe` |
| 21 | 0.01% | 2.0ms | 2,109 | `Kompo\Auth\Teams\CacheKeyBuilder::getTagsForCacheType` |
| 22 | 0.01% | 1.8ms | 37 | `Kompo\Auth\Teams\Security\SecurityMetadataRegistry::collectAllProtectionGroups` |
| 23 | 0.01% | 1.8ms | 37 | `Kompo\Auth\Teams\Security\SecurityServiceFactory->createServicesForModel` |
| 24 | 0.01% | 1.5ms | 8 | `Kompo\Auth\Teams\Security\BatchPermissionService->batchLoadFieldProtectionPermissions` |
| 25 | 0.01% | 1.5ms | 39 | `Kompo\Auth\Models\Plugins\HasSecurity->initializeServices` |
| 26 | 0.01% | 1.4ms | 34 | `Kompo\Auth\Teams\Cache\UserContextCache->isSuperAdmin` |
| 27 | 0.01% | 1.3ms | 4 | `App\Services\Events\EventAudienceService->App\Services\Events\{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\app\Services\Events\EventAudienceService.php:92-95}` |
| 28 | 0.01% | 1.3ms | 100 | `Kompo\Auth\Models\Plugins\HasSecurity->newCollection` |
| 29 | 0.00% | 1.1ms | 56 | `permissionMustBeAuthorized` |
| 30 | 0.00% | 1.1ms | 50 | `Kompo\Auth\Teams\Cache\CachedPermissionResolver->userHasPermission` |

## Top 30 functions by INCLUSIVE cost (security layer)

| # | % total | Inclusive | Calls | Function |
|---:|---:|---:|---:|---|
| 1 | 90.35% | 22.97s | 1 | `Kompo\Auth\Http\Middleware\EnsureResetPasswordWhenRequired->handle` |
| 2 | 58.60% | 14.89s | 48 | `Kompo\Auth\Teams\Security\ReadSecurityService->Kompo\Auth\Teams\Security\{closure:C:\Users\jkend\Documents\Projects\kompo\auth\src\Teams\Security\ReadSecurityService.php:58-68}` |
| 3 | 56.12% | 14.26s | 4 | `Kompo\Auth\Teams\Security\ReadSecurityService->applyReadSecurityScope` |
| 4 | 56.12% | 14.26s | 4 | `Kompo\Auth\Teams\Security\ReadSecurityService->applyTeamBasedRestrictions` |
| 5 | 55.68% | 14.15s | 4 | `Kompo\Auth\Teams\Security\ReadSecurityService->Kompo\Auth\Teams\Security\{closure:C:\Users\jkend\Documents\Projects\kompo\auth\src\Teams\Security\ReadSecurityService.php:126-132}` |
| 6 | 55.54% | 14.12s | 4 | `Kompo\Auth\Teams\Security\ReadSecurityService->applyTeamRestrictions` |
| 7 | 55.23% | 14.04s | 4 | `Kompo\Auth\Teams\Security\ReadSecurityService->applyScopeBasedTeamSecurity` |
| 8 | 53.85% | 13.69s | 1 | `App\Services\Events\EventAudienceService->applyVisibilityConstraint` |
| 9 | 53.85% | 13.69s | 1 | `App\Services\Events\Cache\CachedEventAudienceService->applyVisibilityConstraint` |
| 10 | 50.70% | 12.89s | 51 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->rememberRequest` |
| 11 | 21.02% | 5.34s | 4,889 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->remember` |
| 12 | 18.22% | 4.63s | 2,109 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->cacheRememberWithTags` |
| 13 | 13.62% | 3.46s | 1,584 | `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getAncestorTeamsUntilLevel` |
| 14 | 3.73% | 947.1ms | 8,165 | `Kompo\Auth\Teams\Security\SecurityMetadataRegistry::for` |
| 15 | 3.51% | 893.3ms | 1,584 | `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getBatchAncestorTeamIds` |
| 16 | 3.12% | 792.7ms | 56 | `permissionMustBeAuthorized` |
| 17 | 3.05% | 774.4ms | 57 | `Kompo\Auth\Models\Teams\Permission::findByKey` |
| 18 | 3.02% | 768.9ms | 57 | `Kompo\Auth\Teams\Cache\PermissionDefinitionCache->permissionByKey` |
| 19 | 2.45% | 621.7ms | 2,088 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->ttlForTag` |
| 20 | 2.43% | 616.8ms | 14,454 | `Kompo\Auth\Models\Plugins\HasSecurity->getAttribute` |
| 21 | 2.41% | 613.0ms | 8 | `Kompo\Auth\Teams\TeamHierarchyService->executeDescendantsQuery` |
| 22 | 2.41% | 612.4ms | 8 | `Kompo\Auth\Teams\TeamHierarchyService->getDescendantTeamIds` |
| 23 | 2.30% | 584.1ms | 19 | `Kompo\Auth\Teams\Cache\PermissionDefinitionCache->Kompo\Auth\Teams\Cache\{closure:C:\Users\jkend\Documents\Projects\kompo\auth\src\Teams\Cache\PermissionDefinitionCache.php:20-20}` |
| 24 | 2.26% | 575.7ms | 1,584 | `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getDescendantTeamIds` |
| 25 | 1.52% | 386.0ms | 1 | `App\Services\Events\EventAudienceService->App\Services\Events\{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\app\Services\Events\EventAudienceService.php:75-99}` |
| 26 | 1.50% | 381.5ms | 100 | `Kompo\Auth\Models\Plugins\HasSecurity->newCollection` |
| 27 | 1.49% | 378.3ms | 8 | `Kompo\Auth\Support\SecuredModelCollection->autoBatch` |
| 28 | 1.49% | 378.2ms | 8 | `Kompo\Auth\Support\SecuredModelCollection->autoBatchLoadPermissions` |
| 29 | 1.49% | 377.6ms | 8 | `Kompo\Auth\Teams\Security\BatchPermissionService->batchLoadFieldProtectionPermissions` |
| 30 | 1.42% | 360.3ms | 37 | `Kompo\Auth\Models\Plugins\HasSecurity->onBoot` |

## Top callers for top-10-by-self


### `Kompo\Auth\Teams\Security\SecurityMetadataRegistry::for`
- Self: 339.8ms (1.34% of total) — Inclusive: 947.1ms — Calls: 8,165

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `Kompo\Auth\Models\Plugins\HasSecurity->getAttribute` | 7,994 | 335.0ms |
| `Kompo\Auth\Models\Plugins\HasSecurity->setupFieldProtectionSafe` | 37 | 272.0ms |
| `Kompo\Auth\Models\Plugins\HasSecurity->newCollection` | 100 | 0.2ms |
| `Kompo\Auth\Models\Plugins\HasSecurity->interceptRelation` | 20 | 0.0ms |
| `Kompo\Auth\Teams\Security\TeamSecurityService->massRestrictByTeam` | 4 | 0.0ms |

### `Kompo\Auth\Models\Plugins\HasSecurity->getAttribute`
- Self: 137.2ms (0.54% of total) — Inclusive: 616.8ms — Calls: 14,454

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `Condoedge\Utils\Models\ModelBase->getAttribute` | 14,454 | 479.6ms |

### `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getBatchAncestorTeamIds`
- Self: 94.2ms (0.37% of total) — Inclusive: 893.3ms — Calls: 1,584

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `App\Services\Events\EventVisibilityDataResolverImpl->visibleEventTeamIdsForViewer` | 1,584 | 799.1ms |

### `Kompo\Auth\Teams\Cache\AuthCacheLayer->remember`
- Self: 65.4ms (0.26% of total) — Inclusive: 5.34s — Calls: 4,889

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getAncestorTeamsUntilLevel` | 1,584 | 3.39s |
| `Kompo\Auth\Teams\Cache\PermissionDefinitionCache->permissionByKey` | 57 | 753.6ms |
| `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getBatchAncestorTeamIds` | 1,584 | 575.2ms |
| `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getDescendantTeamIds` | 1,584 | 529.8ms |
| `Kompo\Auth\Teams\Cache\CachedPermissionResolver->getTeamsWithPermissionForUser` | 20 | 19.5ms |

### `Kompo\Auth\Teams\Cache\AuthCacheLayer->cacheRememberWithTags`
- Self: 38.4ms (0.15% of total) — Inclusive: 4.63s — Calls: 2,109

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `Kompo\Auth\Teams\Cache\AuthCacheLayer->remember` | 2,109 | 4.59s |

### `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getAncestorTeamsUntilLevel`
- Self: 34.8ms (0.14% of total) — Inclusive: 3.46s — Calls: 1,584

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `App\Services\Events\EventVisibilityDataResolverImpl->resolveAnchorId` | 1,584 | 3.43s |

### `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getDescendantTeamIds`
- Self: 19.2ms (0.08% of total) — Inclusive: 575.7ms — Calls: 1,584

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `App\Services\Events\EventVisibilityDataResolverImpl->visibleEventTeamIdsForViewer` | 1,584 | 556.5ms |

### `Kompo\Auth\Teams\Cache\AuthCacheLayer->ttlForTag`
- Self: 13.6ms (0.05% of total) — Inclusive: 621.7ms — Calls: 2,088

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `Kompo\Auth\Teams\Cache\AuthCacheLayer->remember` | 2,088 | 608.1ms |

### `Kompo\Auth\Teams\Security\SecurityMetadataRegistry::compute`
- Self: 7.9ms (0.03% of total) — Inclusive: 275.3ms — Calls: 37

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `Kompo\Auth\Teams\Security\SecurityMetadataRegistry::for` | 37 | 267.4ms |

### `Kompo\Auth\Teams\CacheKeyBuilder::teamDescendants`
- Self: 6.4ms (0.03% of total) — Inclusive: 13.9ms — Calls: 1,584

| Caller | Calls | Inclusive cost charged here |
|---|---:|---:|
| `Kompo\Auth\Teams\Cache\CachedTeamHierarchyService->getDescendantTeamIds` | 1,584 | 7.5ms |

## Hot per-row loops (call count > 100k inside security set)

_None_
