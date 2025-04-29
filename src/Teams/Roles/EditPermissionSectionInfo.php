<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Models\Teams\PermissionSection;

class EditPermissionSectionInfo extends Modal
{
    public $model = PermissionSection::class;

    public $_Title = 'translate.edit-section-info';

    public $hasSubmitButton = false;

    protected $refreshId;

    public function created()
    {
        $this->refreshId = $this->prop('refresh_id');
    }

    public function body()
    {
        return _Rows(
            _Translatable('generic.name')->name('name'),

            _FlexEnd(
                _SubmitButton('generic.save')->closeModal()->refresh($this->refreshId),
            )
        );
    }
}