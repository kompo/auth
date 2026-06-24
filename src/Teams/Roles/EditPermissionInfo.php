<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Modal;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;
use Kompo\Form;

class EditPermissionInfo extends Modal
{
    public $model = Permission::class;

    public $class = 'overflow-y-auto mini-scroll max-w-2xl';

    public $_Title = 'auth-edit-permission-info';

    public $hasSubmitButton = false;

    protected $refreshId;

    public function created()
    {
        $this->refreshId = $this->prop('refresh_id');
    }

    public function afterSave()
    {
        app(PermissionCacheInvalidator::class)->permissionChanged(
            $this->model,
            [$this->model->permission_key],
            [$this->model->permission_section_id],
        );
    }

    public function body()
    {
        return _Rows(
            _CardLevel4(
                _Html('auth-permission-key'),
                _Html($this->model->permission_key)->class('text-gray-700'),
            )->p4()->class('mb-2'),

            _TranslatableEditor('auth-permission')->name('permission_name'),

            _TranslatableEditor('auth-permission-read')->name('permission_description_read'),
            _TranslatableEditor('auth-permission-write')->name('permission_description_write'),

            _MultiSelect('auth-permission-dependencies')->name('dependencies')
                ->options($this->dependencyOptions()),

            _Rows(
                new PermissionInfoSlidesTable(['permission_id' => $this->model->id]),
            )->class('mt-4'),

            _FlexEnd(
                _SubmitButton('generic.save')->closeModal()->refresh($this->refreshId),
            )
        );
    }

    protected function dependencyOptions(): array
    {
        return Permission::where('id', '!=', $this->model->id)
            ->orderBy('permission_key')
            ->get()
            ->mapWithKeys(fn (Permission $p) => [$p->id => ($p->permission_name ?: $p->permission_key)])
            ->all();
    }
}
