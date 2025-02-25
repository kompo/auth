<?php

namespace Kompo\Auth\Tags;

use Kompo\Auth\Common\Modal;
use Kompo\Auth\Models\Tags\Tag;

class TagForm extends Modal
{
	public $model = Tag::class;

	public $_Title = 'tags.manage-tag';
	public $_Icon = 'image';

	public $class = 'overflow-y-auto mini-scroll';
	public $style = 'max-height: 95vh';

    protected $tagType;
    protected $tagContext;

    public function created()
    {
        $this->tagType = $this->prop('tag_type');
        $this->tagContext = $this->prop('tag_context');
    }

    public function beforeSave()
    {
        $this->model->tag_type = $this->tagType;
        $this->model->context = $this->tagContext;
        $this->model->team_id = currentTeamId();
    }

    public function body()
    {
    	return _Rows(
            _Translatable('tags.name')->name('name'),
            _SubmitButton('tags.save'),
    	);
    }
}
