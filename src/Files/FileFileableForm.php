<?php

namespace App\Kompo\Library;

use App\Models\Library\File;
use Kompo\Form;

class FileFileableForm extends Form
{
    public $model = \App\Models\Library\File::class;

    public $style = 'max-height:95vh; min-width: 350px;';

    protected $defaultType;
    protected $defaultId;

    public function created()
    {
        $this->defaultType = $this->model->fileable_type ?: collect(File::typesOptions())->keys()->first();
        $this->defaultId = $this->model->fileable_id;
    }

    public function render()
    {
        return _Columns(
            _Select()->placeholder('translate.type-fileable')->options(
                collect(File::typesOptions())->mapWithKeys(
                    fn($label, $value) => [$value => ucfirst($label[0])]
                ),
            )->default($this->defaultType)->name('fileable_type')
            ->selfGet('getSelectFileable')->inPanel1(),
            _Panel1(
                $this->getSelectFileable()
            ),
        );
    }

    public function getSelectFileable()
    {
        return new SelectFileable([
            'fileable_type' => request('fileable_type') ?: $this->defaultType,
            'fileable_id' => request('fileable_id') ?: $this->defaultId,
        ]);
    }

    public function rules()
    {
        return [
            'fileable_type' => 'required',
        ];
    }
}
