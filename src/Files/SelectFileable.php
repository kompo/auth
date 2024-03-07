<?php

namespace Kompo\Auth\Files;

use Illuminate\Database\Eloquent\Relations\Relation;
use Kompo\Form;

class SelectFileable extends Form
{
    protected $fileableType;
    protected $fileableId;

    public function created()
    {
        $this->fileableType = $this->prop('fileable_type');
        $this->fileableId = $this->prop('fileable_id');
    }

    public function render()
    {
        // I want to convert fileableType to a select option
        $model = Relation::morphMap()[$this->fileableType];
        $model = $model::query();

        return _Select()->placeholder('ka::files.type-fileable')
            ->options(
                $model->forTeam(currentTeamId())->get()->mapWithKeys(
                    fn ($model) => [$model->id => $model->display]
                )
            )->default($this->fileableId)
            ->name('fileable_id')->class('mb-10');
    }
}
