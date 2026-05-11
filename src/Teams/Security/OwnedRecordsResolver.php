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

        return [];
    }
}
