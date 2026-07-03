<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Query;
use Kompo\Auth\Models\Teams\PermissionInfoSlide;

/**
 * Admin sub-list (embedded in EditPermissionInfo) to manage a permission's
 * carousel slides: add, edit, drag-reorder and delete.
 */
class PermissionInfoSlidesTable extends Query
{
    public $perPage = 100;

    public $orderable = 'position';

    public $dragHandle = '.cursor-move';

    protected $permissionId;

    public function created()
    {
        $this->permissionId = $this->prop('permission_id');
        $this->id = 'permission-info-slides-' . $this->permissionId;
    }

    public function query()
    {
        return PermissionInfoSlide::where('permission_id', $this->permissionId)->ordered();
    }

    public function top()
    {
        return _FlexBetween(
            _Html('auth-permission-slides')->class('font-semibold'),
            _Button('auth-permission-add-slide')->icon('plus')
                ->selfGet('getSlideForm')->inModal(),
        )->class('mb-2');
    }

    public function render($slide)
    {
        return _FlexBetween(
            _Flex(
                _Html()->icon(_Svg('selector'))->class('cursor-move text-gray-400'),
                _Html($slide->media_type->label() . ($slide->scribe_id ? ' · ' . $slide->scribe_id : ''))->class('text-sm'),
                !$slide->caption ? null : _Html(\Str::limit((string) $slide->caption, 50))->class('text-xs text-gray-500'),
            )->class('gap-3 items-center'),
            _Flex(
                _Link('crm.edit')->selfGet('getSlideForm', ['id' => $slide->id])->inModal(),
                _DeleteLink('auth-permission-delete-slide')->byKey($slide),
            )->class('gap-3 items-center text-sm'),
        )->class('py-2 px-1 border-b border-gray-200');
    }

    public function getSlideForm()
    {
        return new PermissionInfoSlideForm(request('id'), [
            'permission_id' => $this->permissionId,
            'refresh_id' => $this->id,
        ]);
    }
}
