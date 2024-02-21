<?php

namespace Kompo\Auth\Models\Tags;

use Kompo\Auth\Models\ModelBase;

class Taggable extends ModelBase
{
	protected $table = 'taggable_tag';

	/* RELATIONS */
	public function tag()
	{
		return $this->belongsTo(Tag::class);
	}

    public function taggable()
    {
        return $this->morphTo();
    }
}
