<?php

namespace Kompo\Auth\Models;

class Model extends ModelBase
{
    use \Kompo\Auth\Models\Traits\HasAddedModifiedByTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
}
