<?php

namespace Kompo\Auth\Models\Tags;

use Kompo\Auth\Models\Model;
use Kompo\Auth\Models\Teams\BelongsToTeamTrait;

class Tag extends Model
{
	use BelongsToTeamTrait;
	use \Kompo\Database\HasTranslations;

	const TAG_TYPE_GENERAL = 0;
	
	protected $translatable = ['name'];
	protected $casts = [
		'context' => TagContextEnum::class,
	];

	/* RELATIONS */
	public function taggables() //optional remove if not used
	{
		return $this->hasMany(Taggable::class);
	}

	/* ATTRIBUTES */
	public function getBgColorAttribute()
	{
		return $this->isSystemTag() ? 'bg-level3' : 'bg-info';
	}

	public function isSystemTag()
	{
		return is_null($this->team_id);
	}

	/* SCOPES */
	public function scopeVisibleForTeam($query)
	{
		return $query->where(fn($q) => $q->where('team_id', currentTeamId())->orWhere('context', TagContextEnum::ALL));
	}

	public function scopeOfType($query, $type)
	{
		return $query->where('tag_type', $type);
	}

	public function scopeOfContext($query, $context)
	{
		return $query->where('context', $context);
	}

	/* ELEMENTS */
	public function getTagPill()
	{
		return _Pill($this->name)->class('text-white mr-2 mb-2')->class($this->bg_color);
	}
	
	public static function multiSelect($label = '', $relatedToModel = true)
	{
	    return _MultiSelect($label)
	    	->icon('icon-tags')
	    	->placeholder('Tags')->name('tags', $relatedToModel)
	        ->options(
	            static::pluck('name', 'id')
	        );
	}

	/* ACTIONS */
	
	public function addTaggable($taggableId, $taggableType)
	{
		$taggable = new Taggable();
		$taggable->taggable_id = $taggableId;
		$taggable->taggable_type = $taggableType;
		$taggable->tag_id = $this->id;
		$taggable->save();
	}
	
	public function deletable()
	{
		return auth()->user() && ($this->team_id == auth()->user()->current_team_id);
	}

	public function editable()
	{
		return $this->deletable();
	}
}
