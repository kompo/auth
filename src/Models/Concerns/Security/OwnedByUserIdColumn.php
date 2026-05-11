<?php

namespace Kompo\Auth\Models\Concerns\Security;

/**
 * Drop-in implementation of `HasOwnedRecords` for the common pattern: a
 * `user_id` foreign key on the model's table.
 *
 *   class Note extends ModelBase implements HasOwnedRecords
 *   {
 *       use OwnedByUserIdColumn;
 *   }
 *
 * If your owned-by column is named something else, implement
 * `ownedRecordIdsForUser` directly instead of using this trait.
 */
trait OwnedByUserIdColumn
{
    public function ownedRecordIdsForUser(int $userId): array
    {
        return static::query()
            ->where('user_id', $userId)
            ->pluck($this->getKeyName())
            ->all();
    }
}
