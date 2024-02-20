<?php 

namespace Kompo\Auth\Models\Tags;

trait HasManyTagsTrait
{
	/* RELATIONS */
    public function tags()
	{
		return $this->hasMany(Tag::class);
	}
}