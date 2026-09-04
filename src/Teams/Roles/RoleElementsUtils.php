<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;

trait RoleElementsUtils
{
    protected function noWriteBanner()
    {
        return _WarningBanner(
            'auth.you-dont-have-permissions-to-change-the-assignations',
            'auth.you-dont-have-permissions-to-change-the-assignations-sub',
        )->class('mb-4');
    }

    protected function toolbar(array $selectedRoles, string $search)
    {
        return _Flex(
            _MultiSelect()->name('roles', false)->placeholder('auth-roles')
                ->options(getRoles()->pluck('name', 'id')->all())->default($selectedRoles)
                ->class('flex-1 min-w-[260px] !mb-0')
                ->onChange(fn($e) => $this->gridRequest($e)->after(300)),
            _Input()->name('permission_name', false)->placeholder('permissions-search-placeholder')
                ->icon(_SaxSvg('search-normal', 16))->value($search)
                ->class('w-[280px] !mb-0')->inputClass('!rounded-full')
                ->debounce(350)->onInput(fn($e) => $this->gridRequest($e)),
        )->class('roles-toolbar !items-end gap-4 mb-3');
    }

    protected function gridRequest($e, array $params = [])
    {
        // An empty PHP array reaches Vue as a JS array, which URLSearchParams serializes to nothing.
        return $e->selfGet('getGrid', $params ?: null)->withAllFormValues()->withLoadingIn('roles-grid-wrap')->inPanel('roles-grid');
    }

    protected function grid(array $expanded)
    {
        $hasRoles = $this->roles()->isNotEmpty();
        $sections = $hasRoles ? $this->visibleSections() : collect();

        return _Div(
            $this->rolesHeader(),
            $this->gridHint($hasRoles, $sections->isNotEmpty()),
            ...$sections->map(fn($section) => _Panel($this->sectionContent($section, in_array($section->id, $expanded)))->id($this->sectionPanelId($section)))->all(),
        )->class('roles-grid-inner')->style('--rm-n:' . max(1, $this->roles()->count() - 1));
    }

    protected function gridHint(bool $hasRoles, bool $hasSections)
    {
        if (!$hasRoles) {
            return $this->emptyState(__('permissions-no-role-selected'), __('permissions-no-role-selected-sub'));
        }

        return $hasSections ? null : $this->emptyState(__('permissions-no-results', ['term' => e($this->search())]));
    }

    protected function rolesHeader()
    {
        $counts = ['permissions' => $this->sections()->sum(fn($section) => $section->permissions->count()), 'sections' => $this->sections()->count()];

        return _Div(
            _Div(
                _Html('auth-permission')->class('roles-corner-eyebrow'),
                _Html(__('permissions-matrix-corner', $counts))->class('roles-corner-meta'),
                _Flex(
                    _Link('permissions-expand-all')->onClick(fn($e) => $this->gridRequest($e, ['expand_all' => 1])),
                    _Link('permissions-collapse-all')->onClick(fn($e) => $this->gridRequest($e, ['collapse_all' => 1])),
                )->class('roles-corner-actions'),
            )->class('roles-cell roles-first roles-corner'),
            ...$this->roles()->map(fn($role) => $this->roleHeader($role))->all(),
        )->id('roles-header')->class('roles-row roles-rail');
    }

    protected function roleHeader($role)
    {
        return _Div(
            _Flex(
                _Html(e($role->name))->class('roles-role-name')->attr(['title' => $role->name]),
                !$role->from_system ? null : _Html('permissions-system-role')->class('roles-role-system'),
            )->class('gap-2 !items-start'),
            _Html(__('permissions-role-meta', $this->roleStats($role)))->class('roles-role-meta'),
            $this->roleMenu($role),
        )->id('role-header-' . $role->id)->class('roles-cell roles-role-header');
    }

    protected function roleMenu($role)
    {
        return _TripleDotsDropdown(
            _Link('permissions-edit')->icon(_SaxSvg('edit-2', 16))->class('roles-menu-item')
                ->selfGet('getRoleForm', ['id' => $role->id])->inModal(),
            !$role->canSeeDeletedButton() ? null : ($role->hasPendingActionsToDelete()
                ? _Link('permissions-delete')->icon(_SaxSvg('trash', 16))->class('roles-menu-item is-danger')
                    ->selfPost('getPendingActionsToDeleteRoleModal', ['id' => $role->id])->inModal()
                : _DeleteLink('permissions-delete')->icon(_SaxSvg('trash', 16))->class('roles-menu-item is-danger')
                    ->selfPost('deleteRole', ['id' => $role->id])),
        )->class('roles-role-menu')->checkAuthWrite('Role');
    }

    protected function sectionPanelId($section): string
    {
        return 'section-' . $section->id;
    }

    protected function sectionHeadPanelId($section): string
    {
        return 'section-head-' . $section->id;
    }

    protected function sectionBodyPanelId($section): string
    {
        return 'section-body-' . $section->id;
    }

    protected function sectionContent($section, bool $expanded)
    {
        $panelId = $this->sectionPanelId($section);

        return _Rows(
            _Panel($this->sectionHeader($section, $expanded))->id($this->sectionHeadPanelId($section)),
            _Link()->class('roles-section-fetch hidden')
                ->onClick(fn($e) => $e->selfGet('toggleSection', ['section_id' => $section->id, 'expand' => 1])
                    ->withAllFormValues()->withLoadingIn($panelId)->inPanel($panelId)),
            !$expanded ? null : _Panel($this->sectionBody($section))->id($this->sectionBodyPanelId($section))->class('roles-section-body'),
        )->class('roles-section');
    }

    protected function sectionBody($section)
    {
        return _Rows(
            _Hidden()->name('expanded[' . $section->id . ']', false)->value(1),
            ...$this->visiblePermissions($section)->map(fn($permission) => $this->permissionRow($permission, $section))->all(),
        );
    }

    protected function sectionHeader($section, bool $expanded)
    {
        return _Div(
            _Div(
                _Link(e($section->name) . $this->sectionCount($section))->icon(_SaxSvg('arrow-down-1', 16))
                    ->class('roles-section-toggle')
                    ->onClick(fn($e) => $e->run($this->sectionToggleJs()) && $e->toggleId($this->sectionBodyPanelId($section), false)),
                !isAppSuperAdmin() ? null : _Link()->icon('pencil')->class('roles-section-edit')
                    ->selfGet('getEditSectionInfoForm', ['section_id' => $section->id])->inModal(),
            )->class('roles-cell roles-first'),
            ...$this->roles()->map(fn($role) => $this->aggregateCell($role, $section))->all(),
        )->class('roles-row roles-section-header')->attr(['data-expanded' => (int) $expanded]);
    }

    /**
     * First expand fetches the rows; afterwards the loaded body is only shown/hidden (see toggleId).
     * The arrow flips with the click instead of reading v-show: run() fires on $nextTick, which may
     * land before Vue applies display:none, so observing the DOM gave a stale (inverted) state.
     */
    protected function sectionToggleJs(): string
    {
        return <<<'JS'
            ({ el }) => {
                const section = el.element.closest('.roles-section');
                if (!section.querySelector('.roles-section-body')) return section.querySelector('.roles-section-fetch').click();
                const header = section.querySelector('.roles-section-header');
                header.dataset.expanded = header.dataset.expanded === '1' ? '0' : '1';
            }
        JS;
    }

    protected function sectionCount($section): string
    {
        $shown = $this->visiblePermissions($section)->count();
        $total = $section->permissions->count();
        $filtered = $shown < $total;

        return '<span class="roles-section-count' . ($filtered ? ' is-filtered' : '') . '">' . ($filtered ? "$shown / $total" : $total) . '</span>';
    }

    protected function aggregateCell($role, $section)
    {
        $aggregate = $this->aggregate($role, $section);

        $pill = !$this->canWrite
            ? _Html($aggregate->html())->class('opacity-60')
            : _Dropdown()->icon($aggregate->html())->noCaret()->openOnClick()
                ->togglerClass('roles-agg-toggle')->class('roles-agg-menu')
                ->submenu(...$this->applyMenu($role, $section, $aggregate));

        return _Div($pill)->class('roles-cell');
    }

    protected function applyMenu($role, $section, SectionAggregate $aggregate): array
    {
        $visible = $this->visiblePermissions($section);
        $readOnlyCount = $visible->reject(fn($permission) => $permission->supportsType(PermissionTypeEnum::ALL))->count();
        $filtered = $visible->count() < $section->permissions->count();

        $note = $filtered
            ? __('permissions-filter-active-applies-to', ['n' => $visible->count(), 'total' => $section->permissions->count()])
            : __('permissions-concerned-count', ['n' => $visible->count()]);
        $note .= $readOnlyCount ? ' ' . __('permissions-concerned-read-only', ['n' => $readOnlyCount]) : '';

        $types = collect(PermissionTypeEnum::forSection($section))->map(fn($value) => PermissionTypeEnum::from($value));

        return [
            _Html(__('permissions-apply-to-section') . '<small>' . e($section->name) . ' · ' . e($role->name) . '</small>')->class('roles-menu-head'),
            ...$types->map(fn($type) => $this->applyMenuItem($role, $section, $type, $aggregate->kind))->all(),
            _Html()->class('roles-menu-sep'),
            $this->applyMenuItem($role, $section, null, $aggregate->kind),
            _Html($note)->class('roles-menu-note'),
        ];
    }

    protected function applyMenuItem($role, $section, ?PermissionTypeEnum $type, string $currentKind)
    {
        $panelId = $this->sectionPanelId($section);
        $code = $type?->code() ?? 'none';

        return _Link($type ? $type->label() : 'permissions-remove-all-access')
            ->icon('<span class="roles-menu-swatch perm-chip perm-' . $code . '"></span>')
            ->class('roles-menu-item')->class($currentKind === $code ? 'is-current' : '')
            ->onClick(fn($e) => $e->selfPost('changeRolePermissionSection', [
                'role' => $role->id,
                'permissionSection' => $section->id,
                'type' => $type?->value ?? 0,
            ])->withAllFormValues()->withLoadingIn($panelId)->inPanel($panelId));
    }

    protected function permissionRow($permission, $section)
    {
        return _Div(
            _Div(
                _Link($this->highlightedName($permission))->class('roles-permission-name')
                    ->selfGet('getPermissionInfoModal', ['permission_id' => $permission->id])->inModal(),
                $permission->supportsType(PermissionTypeEnum::ALL) ? null : _Html('permissions-read-only-permission')->class('roles-permission-tag'),
            )->class('roles-cell roles-first'),
            ...$this->roles()->map(fn($role) => $this->cell($role, $permission, $section))->all(),
        )->class('roles-row roles-permission')->class($permission->object_type?->classes() ?? '');
    }

    protected function cell($role, $permission, $section)
    {
        $headerPanelId = $this->sectionHeadPanelId($section);

        return _Div(
            _CheckboxMultipleStates(
                $role->id . '-' . $permission->id,
                PermissionTypeEnum::forPermission($permission),
                PermissionTypeEnum::cellClassesForPermission($permission),
                $this->typeOf($role, $permission),
                $this->canWrite,
            )->config(['doesNotFill' => true])->class('!mb-0')
            ->when($this->canWrite, fn($el) => $el->onChange(
                fn($e) => $e->selfPost('changeRolePermission', ['role' => $role->id, 'permission' => $permission->id])
                    ->withAllFormValues()->inPanel($headerPanelId)
            )),
        )->class('roles-cell');
    }

    protected function highlightedName($permission): string
    {
        $name = (string) $permission->permission_name;
        $term = $this->search();
        $position = $term === '' ? false : mb_stripos($name, $term);

        if ($position === false) {
            return e($name);
        }

        $length = mb_strlen($term);

        return e(mb_substr($name, 0, $position))
            . '<mark>' . e(mb_substr($name, $position, $length)) . '</mark>'
            . e(mb_substr($name, $position + $length));
    }

    protected function emptyState(string $title, ?string $subtitle = null)
    {
        return _Rows(
            _Html($title)->class('roles-empty-title'),
            !$subtitle ? null : _Html($subtitle),
        )->class('roles-empty');
    }
}
