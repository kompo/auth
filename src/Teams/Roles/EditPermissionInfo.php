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

            _TranslatableEditor('auth-permission-read-desc-label')->name('permission_description_read'),
            _TranslatableEditor('auth-permission-write-desc-label')->name('permission_description_write'),

            $this->structuralDependencies(),

            _MultiSelect('auth-permission-dependency-recommended')->name('recommendedDependencies')
                ->options($this->dependencyOptions())
                ->comment('auth-permission-dependency-recommended-help'),

            _Rows(
                new PermissionInfoSlidesTable(['permission_id' => $this->model->id]),
            )->class('mt-4'),

            _FlexEnd(
                _SubmitButton('generic.save')->closeModal()->refresh($this->refreshId),
            )->class('mt-4'),
        );
    }

    // Structural dependencies are declared in code and only listed here.
    protected function structuralDependencies()
    {
        $deps = $this->model->structuralDependencies;

        return $deps->isEmpty() ? null : _Rows(
            _Html('auth-permission-dependency-structural')->class('text-sm font-medium mb-1'),
            _Flex(
                $deps->map(fn (Permission $p) => _Html($p->permission_name ?: $p->permission_key)
                    ->icon(_Sax('lock', 14))->class('text-xs px-2 py-1 rounded bg-gray-100 text-gray-700 border border-gray-300')),
            )->class('gap-2 flex-wrap'),
            _Html('auth-permission-dependency-structural-help')->class('text-xs text-gray-500 mt-1'),
        )->class('mb-4');
    }

    protected function dependencyOptions(): array
    {
        return Permission::where('id', '!=', $this->model->id)
            ->whereNotIn('id', $this->model->structuralDependencies()->pluck('permissions.id'))
            ->orderBy('permission_key')
            ->get()
            ->mapWithKeys(fn (Permission $p) => [$p->id => ($p->permission_name ?: $p->permission_key)])
            ->all();
    }
}
