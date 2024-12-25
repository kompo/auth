<?php

namespace Kompo\Auth\Models\Traits;

use App\Models\User;

trait HasAddedModifiedByTrait
{
    public static function bootHasAddedModifiedByTrait()
    {
        static::saving(function ($model) {
            $model->manageAddedModifiedBy();
        });
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
            if (!$this->getKey() || !$this->exists) {
                $this->added_by = $this->added_by ?: auth()->id();
            }

            $this->modified_by = auth()->id();
        }
    }
}
