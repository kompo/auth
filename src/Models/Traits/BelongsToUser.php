<?php 

namespace Kompo\Auth\Models\Traits;

use App\Models\User;

trait BelongsToUser
{
    /* RELATIONS */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* ACTIONS */
    public function setUserId($userId = null)
    {
        $this->user_id = $userId ?: auth()->id();
    }

    /* SCOPES */
    public function scopeForAuthUser($query, $userId = null)
    {
        $query->where('user_id', $userId ?: auth()->id());
    }
}