# Callee breakdown for hot security functions

For each function below, the table lists every downstream callee it invokes,
ranked by inclusive cost charged to that call site. This shows WHERE the time
actually goes after control leaves the named function.


## ReadSecurityService->shouldApplyReadSecurity

Sum of all calls from inside this fn: **13.72s** across 2 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 13.69s | 34 | `permissionMustBeAuthorized` |
| 29.2ms | 37 | `Kompo\Auth\Teams\Security\ReadSecurityService->hasReadSecurityRestrictions` |

## ReadSecurityService->setupReadSecurity

Sum of all calls from inside this fn: **13.72s** across 2 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 13.72s | 37 | `Kompo\Auth\Teams\Security\ReadSecurityService->shouldApplyReadSecurity` |
| 0.1ms | 10 | `Illuminate\Database\Eloquent\Model::addGlobalScope` |

## permissionByKey closure (line 20)

Sum of all calls from inside this fn: **13.39s** across 2 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 13.15s | 39 | `Illuminate\Database\Eloquent\Builder->first` |
| 239.9ms | 39 | `Illuminate\Database\Eloquent\Model::__callStatic` |

## AuthCacheLayer->cacheRememberWithTags

Sum of all calls from inside this fn: **14.82s** across 5 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 14.81s | 928 | `Illuminate\Support\Facades\Facade::__callStatic` |
| 0.1ms | 1 | `Composer\Autoload\ClassLoader->loadClass` |
| 0.0ms | 1 | `Vonage\NexmoBridge\Autoloader::Vonage\NexmoBridge\{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\vendor\vonage\nexmo-bridge\src\Autoloader.php:97-123}` |
| 0.0ms | 1 | `Illuminate\Foundation\AliasLoader->load` |
| 0.0ms | 1 | `{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\vendor\google\apiclient-services\autoload.php:25-36}` |

## AuthCacheLayer->remember

Sum of all calls from inside this fn: **51.70s** across 7 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 36.71s | 1 | `App\Services\Events\Cache\CachedEventVisibilityDataResolver->App\Services\Events\Cache\{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\app\Services\Events\Cache\CachedEventVisibilityDataResolver.php:31-31}` |
| 14.83s | 464 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->cacheRememberWithTags` |
| 131.1ms | 424 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->ttlForTag` |
| 21.4ms | 34 | `Kompo\Auth\Teams\Cache\CachedPermissionResolver->Kompo\Auth\Teams\Cache\{closure:C:\Users\jkend\Documents\Projects\kompo\auth\src\Teams\Cache\CachedPermissionResolver.php:36-61}` |
| 3.6ms | 3,355 | `php::array_key_exists` |
| 1.5ms | 499 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->trackKeyForTag` |
| 0.9ms | 464 | `Kompo\Auth\Teams\CacheKeyBuilder::getTagsForCacheType` |

## permissionByKey

Sum of all calls from inside this fn: **13.91s** across 6 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 13.89s | 56 | `Kompo\Auth\Teams\Cache\AuthCacheLayer->remember` |
| 16.3ms | 56 | `config` |
| 0.3ms | 1 | `Composer\Autoload\ClassLoader->loadClass` |
| 0.0ms | 1 | `Vonage\NexmoBridge\Autoloader::Vonage\NexmoBridge\{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\vendor\vonage\nexmo-bridge\src\Autoloader.php:97-123}` |
| 0.0ms | 1 | `Illuminate\Foundation\AliasLoader->load` |
| 0.0ms | 1 | `{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\vendor\google\apiclient-services\autoload.php:25-36}` |

## permissionMustBeAuthorized

Sum of all calls from inside this fn: **13.92s** across 7 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 13.90s | 55 | `Kompo\Auth\Models\Teams\Permission::findByKey` |
| 15.2ms | 29 | `config` |
| 10.4ms | 55 | `globalSecurityBypass` |
| 0.3ms | 1 | `Composer\Autoload\ClassLoader->loadClass` |
| 0.0ms | 1 | `Vonage\NexmoBridge\Autoloader::Vonage\NexmoBridge\{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\vendor\vonage\nexmo-bridge\src\Autoloader.php:97-123}` |
| 0.0ms | 1 | `Illuminate\Foundation\AliasLoader->load` |
| 0.0ms | 1 | `{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\vendor\google\apiclient-services\autoload.php:25-36}` |

## EventAudienceService closure (line 90-97)

Sum of all calls from inside this fn: **670.2ms** across 1 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 670.2ms | 4 | `Illuminate\Database\Query\Builder->orWhere` |

## EventAudienceService closure (line 85-98)

Sum of all calls from inside this fn: **692.0ms** across 9 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 690.1ms | 2 | `Illuminate\Database\Query\Builder->where` |
| 1.4ms | 1 | `Illuminate\Support\Facades\Facade::__callStatic` |
| 0.4ms | 1 | `Composer\Autoload\ClassLoader->loadClass` |
| 0.0ms | 1 | `Illuminate\Database\Query\Builder->whereColumn` |
| 0.0ms | 1 | `Illuminate\Database\Query\Builder->select` |
| 0.0ms | 1 | `Vonage\NexmoBridge\Autoloader::Vonage\NexmoBridge\{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\vendor\vonage\nexmo-bridge\src\Autoloader.php:97-123}` |
| 0.0ms | 1 | `Illuminate\Database\Query\Builder->from` |
| 0.0ms | 1 | `Illuminate\Foundation\AliasLoader->load` |
| 0.0ms | 1 | `{closure:C:\Users\jkend\Documents\Projects\kompo\SISC\vendor\google\apiclient-services\autoload.php:25-36}` |

## EventAudienceService closure (line 75-99)

Sum of all calls from inside this fn: **761.6ms** across 1 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 761.6ms | 2 | `Illuminate\Database\Eloquent\Builder->__call` |

## EventAudienceService->applyVisibilityConstraint

Sum of all calls from inside this fn: **37.51s** across 4 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 36.73s | 1 | `App\Services\Events\Cache\CachedEventVisibilityDataResolver->dataFor` |
| 787.6ms | 1 | `Illuminate\Database\Eloquent\Builder->where` |
| 0.0ms | 1 | `App\Services\Events\EventVisibilityData->hasMemberAxisData` |
| 0.0ms | 1 | `App\Services\Events\EventVisibilityData->isViewerOrphan` |

## ReadSecurityService closure (line 123-129)

Sum of all calls from inside this fn: **37.93s** across 2 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 37.91s | 4 | `Kompo\Auth\Teams\Security\ReadSecurityService->applyTeamRestrictions` |
| 24.0ms | 4 | `Kompo\Auth\Teams\Security\ReadSecurityService->addOwnedRecordsAlternative` |

## ReadSecurityService->applyTeamBasedRestrictions

Sum of all calls from inside this fn: **38.00s** across 1 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 38.00s | 4 | `Illuminate\Database\Eloquent\Builder->where` |

## ReadSecurityService closure (line 51-57)

Sum of all calls from inside this fn: **38.00s** across 2 distinct callees.

| Inclusive cost | Calls | Callee |
|---:|---:|---|
| 38.00s | 4 | `Kompo\Auth\Teams\Security\ReadSecurityService->applyReadSecurityScope` |
| 0.9ms | 17 | `Kompo\Auth\Teams\Security\ReadSecurityService->shouldBypassSecurityForQuery` |
