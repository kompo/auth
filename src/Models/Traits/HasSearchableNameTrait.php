<?php 

namespace Kompo\Auth\Models\Traits;

trait HasSearchableNameTrait
{
    /* DEFINE THESE CONSTANTS ON YOUR MODEL FOR THIS TRAIT TO WORK */
    /*
    public const SEARCHABLE_NAME_ATTRIBUTE;
    */

    /* SCOPES */
    public function scopeSearchName($query, $search)
    {
        return $query->where(static::SEARCHABLE_NAME_ATTRIBUTE, 'LIKE', wildcardSpace($search));
    }

    /* ELEMENTS */
    public function getBasicOption()
    {
        return [
            $this->id => $this->{static::SEARCHABLE_NAME_ATTRIBUTE},
        ];
    }
}