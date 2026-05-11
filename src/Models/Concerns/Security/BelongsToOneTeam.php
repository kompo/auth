<?php

namespace Kompo\Auth\Models\Concerns\Security;

use Illuminate\Database\Eloquent\Builder;

/**
 * Drop-in implementation of `ScopedToTeam` for models that own a single
 * `team_id` foreign key.
 *
 *   class Meeting extends ModelBase implements ScopedToTeam
 *   {
 *       use BelongsToOneTeam;
 *   }
 *
 * To use a different column name, declare `protected $teamIdColumn = '...'` on
 * the model (the property is read at runtime, no override needed).
 */
trait BelongsToOneTeam
{
    public function applyTeamSecurityScope(Builder $query, array $teamIds): void
    {
        $query->whereIn(
            $this->getTable() . '.' . $this->teamSecurityColumnName(),
            $teamIds,
        );
    }

    public function getRelatedTeamIds(): array
    {
        $value = $this->getAttribute($this->teamSecurityColumnName());

        return $value === null ? [] : [(int) $value];
    }

    protected function teamSecurityColumnName(): string
    {
        return property_exists($this, 'teamIdColumn')
            ? $this->teamIdColumn
            : 'team_id';
    }
}
