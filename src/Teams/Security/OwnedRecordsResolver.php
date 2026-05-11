<?php

namespace Kompo\Auth\Teams\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Condoedge\Utils\Contracts\Security\HasOwnedRecords;
use Kompo\Auth\Teams\Security\Contracts\OwnedRecordsResolverInterface;

/**
 * Pure compute layer. No static state, no caching — the decorator handles that.
 * Single strategy: the `HasOwnedRecords` contract.
 */
class OwnedRecordsResolver implements OwnedRecordsResolverInterface
{
    public function forUser(int $userId, string $modelClass): array
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        SecurityBypassService::enterBypassContext();
        try {
            return $this->resolve($userId, $modelClass);
        } catch (\Throwable $e) {
            Log::warning('OwnedRecordsResolver compute failed', [
                'model_class' => $modelClass,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        } finally {
            SecurityBypassService::exitBypassContext();
        }
    }

    public function isOwnedBy(int $userId, string $modelClass, $recordId): bool
    {
        return in_array($recordId, $this->forUser($userId, $modelClass), false);
    }

    protected function resolve(int $userId, string $modelClass): array
    {
        $prototype = new $modelClass;

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
