<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Query;
use Kompo\Auth\Models\Teams\PermissionInfoSlide;

/**
 * Admin sub-list (embedded in EditPermissionInfo) to manage a permission's
 * carousel slides: add, edit, reorder and delete. Order is held by the
 * `position` column; reordering swaps positions with the adjacent slide.
 */
class PermissionInfoSlidesTable extends Query
{
    public $perPage = 100;

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
                _Html($this->mediaSummary($slide))->class('text-sm'),
                !$slide->caption ? null : _Html(\Str::limit((string) $slide->caption, 50))->class('text-xs text-gray-500'),
            )->class('gap-3 items-center'),
            _Flex(
                _Link()->icon('icon-up')->selfPost('moveSlide', ['id' => $slide->id, 'dir' => -1])->refresh($this->id),
                _Link()->icon('icon-up')->class('rotate-180')->selfPost('moveSlide', ['id' => $slide->id, 'dir' => 1])->refresh($this->id),
                _Link('crm.edit')->selfGet('getSlideForm', ['id' => $slide->id])->inModal(),
                _DeleteLink('auth-permission-delete-slide')->selfPost('deleteSlide', ['id' => $slide->id])->refresh($this->id),
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

    public function moveSlide()
    {
        $slides = PermissionInfoSlide::where('permission_id', $this->permissionId)->ordered()->get()->values();
        $idx = $slides->search(fn ($s) => $s->id == request('id'));

        if ($idx === false) {
            return;
        }

        $target = $idx + (int) request('dir');

        if ($target < 0 || $target >= $slides->count()) {
            return;
        }

        $a = $slides[$idx];
        $b = $slides[$target];

        [$a->position, $b->position] = [$b->position, $a->position];
        $a->save();
        $b->save();
    }

    public function deleteSlide()
    {
        PermissionInfoSlide::where('permission_id', $this->permissionId)->find(request('id'))?->delete();
    }

    protected function mediaSummary(PermissionInfoSlide $slide): string
    {
        return $slide->media_type->label() . ($slide->scribe_id ? ' · ' . $slide->scribe_id : '');
    }
}
