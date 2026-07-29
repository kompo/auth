<?php

namespace Kompo\Auth\Teams\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Condoedge\Utils\Contracts\Security\HasOwnedRecords;
use Kompo\Auth\Contracts\Security\HasScopedOwnedRecords;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\Security\Contracts\OwnedRecordsResolverInterface;

/**
 * Pure compute layer. No static state, no caching — the decorator handles that.
 * Strategies: `HasScopedOwnedRecords` (verb-aware), then `HasOwnedRecords`.
 */
class OwnedRecordsResolver implements OwnedRecordsResolverInterface
{
    public function forUser(int $userId, string $modelClass, ?PermissionTypeEnum $type = null): array
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        SecurityBypassService::enterBypassContext();
        try {
            return $this->resolve($userId, $modelClass, $type);
        } catch (\Throwable $e) {
            // Fail closed — but loudly. A broken contract implementation used to
            // degrade to "owns nothing" behind a warning, which reads exactly like
            // a user with no records and hides the defect indefinitely.
            Log::error('OwnedRecordsResolver compute failed — ownership resolved as empty', [
                'model_class' => $modelClass,
                'user_id' => $userId,
                'permission_type' => $type?->name,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return [];
        } finally {
            SecurityBypassService::exitBypassContext();
        }
    }

    public function isOwnedBy(int $userId, string $modelClass, $recordId, ?PermissionTypeEnum $type = null): bool
    {
        return in_array($recordId, $this->forUser($userId, $modelClass, $type), false);
    }

    protected function resolve(int $userId, string $modelClass, ?PermissionTypeEnum $type = null): array
    {
        $prototype = new $modelClass;

        if ($prototype instanceof HasScopedOwnedRecords) {
            return array_values((array) $prototype->ownedRecordIdsForUser($userId, $type));
        }

        if ($prototype instanceof HasOwnedRecords) {
            return array_values((array) $prototype->ownedRecordIdsForUser($userId));
        }

        // Auto fallback — parallel to team auto-column. Models with a
        // `user_id` column but no HasOwnedRecords contract still get bulk
        // owner resolution. Registry warns once per class.
        $autoCol = SecurityMetadataRegistry::for($modelClass)['autoUserIdColumn'] ?? null;
        if ($autoCol !== null) {
            return $modelClass::query()
                ->where($autoCol, $userId)
                ->pluck($prototype->getKeyName())
                ->all();
        }

        return [];
    }
}
