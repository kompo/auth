<?php

namespace Kompo\Auth\Models\Tags;

trait MorphToManyTagsTrait
{
	/* RELATIONS */
    public function tags()
	{
		return $this->morphToMany(Tag::class, 'taggable', 'taggable_tag')->withTimestamps();
	}

	/* ELEMENTS */
	public function getTagPills()
	{
		return _Flex2(
			$this->tags->map(fn($tag) => _Pill($tag->name))
		)->class('flex-wrap');
	}

    public function scopeForTags($query, $tagsIds)
    {
        return $query->whereHas(
            'tags', fn($q) => $q->whereIn('tags.id', $tagsIds)
        );
    }

    public function scopeOrForTags($query, $tagsIds)
    {
        return $query->orWhereHas(
            'tags', fn($q) => $q->whereIn('tags.id', $tagsIds)
        );
    }
}
