<?php

namespace Kompo\Auth\Models\Monitoring;

use Kompo\Auth\Models\Model;

class Activity extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    /* RELATIONS */
    public function concern()
    {
        return $this->morphTo();
    }

    /* SCOPES */

    /* CALCULATED FIELD */

    /* ACTIONS */

    /* ELEMENTS */
}
