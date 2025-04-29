<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Form;

class EditPermissionInfo extends Modal
{
    public $model = Permission::class;

    public $_Title = 'translate.edit-permission-info';

    public $hasSubmitButton = false;

    protected $refreshId;

    public function created()
    {
        $this->refreshId = $this->prop('refresh_id');
    }

    public function afterSave()
    {
        Cache::forget('permissions_of_section_' . $this->model->permission_section_id);
    }

    public function body()
    {
        return _Rows(
            _CardLevel4(
                _Html('translate.permission-key'),
                _Html($this->model->permission_key)->class('text-gray-700'),
            )->p4()->class('mb-2'),

            _Rows(
                _Translatable('translate.permission')->name('permission_name'),

                _Translatable('translate.permission-description')->name('permission_description'),
            ),


            _FlexEnd(
                _SubmitButton('generic.save')->closeModal()->refresh($this->refreshId),
            )
        );
    }
}