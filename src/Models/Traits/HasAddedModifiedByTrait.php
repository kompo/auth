<?php

namespace Kompo\Auth\Models\Traits;

use App\Models\User;
use Kompo\Auth\Monitoring\ChangeTypeEnum;
use Kompo\Auth\Monitoring\ModelChangesLog;

trait HasAddedModifiedByTrait
{
    public function save(array $options = [])
    {
        $this->manageAddedModifiedBy();

        parent::save($options);
    }

    /* RELATIONSHIPS */
    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function modifiedBy()
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    /* SCOPES */
    public function scopeForAuthUser($query, $userId = null)
    {
        $query->where('added_by', $userId ?: auth()->id());
    }

    // ACTIONS
    public function manageAddedModifiedBy()
    {
        if (auth()->check()) {
            if (!$this->getKey()) {
                $this->added_by = $this->added_by ?: auth()->id();
            }

            if ($this->getKey() && $this->isDirty()) {
                ModelChangesLog::create([
                    'changeable_type' => $this->getMorphClass(),
                    'changeable_id' => $this->getKey(),
                    'action' => $this->getKey() ? ChangeTypeEnum::UPDATE : ChangeTypeEnum::CREATE,
                    'columns_changed' => array_keys($this->getDirty()),
                    'changed_by' => auth()->id(),
                ]);
            }

            $this->modified_by = auth()->id();
        }
    }
}
