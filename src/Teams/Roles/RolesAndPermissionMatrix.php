<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\Role;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

/**
 * Roles × permissions grid: render() is only the shell, getGrid() builds the grid from the live toolbar values.
 * A cell click answers with its section header so the aggregate pill stays server-true.
 */
class RolesAndPermissionMatrix extends Form
{
    use RoleRequestsUtils;
    use RoleElementsUtils;

    const ID = 'roles-manager-matrix';

    public $id = self::ID;
    public $class = 'roles-matrix';
    protected $preventSubmit = true;
    protected $permissionKey = 'Role';

    protected $canWrite;
    protected $roles;
    protected $sections;
    protected $typeMap;

    public function created()
    {
        $this->canWrite = auth()->user()->hasPermission('Role', PermissionTypeEnum::WRITE);
    }

    public function render()
    {
        return _Rows(
            $this->canWrite ? null : $this->noWriteBanner(),
            $this->dependenciesBanner(),
            $this->toolbar(RolesMatrixView::roles(), RolesMatrixView::search()),
            // Field::doesNotFill() only writes a server-side key; Vue reads the 'doesNotFill' config.
            _Hidden('grid_loader')->config(['doesNotFill' => true])
                ->onLoad(fn($e) => $e->selfGet('getGrid', ['restore' => 1])->withAllFormValues()->inPanel('roles-grid')),
            _Div(
                _Panel(_lazyPlaceholder('rows'))->id('roles-grid')->class('roles-grid mini-scroll'),
            )->id('roles-grid-wrap')->class('relative'),
        )->style(PermissionTypeEnum::cssLabelVars());
    }

    public function getGrid()
    {
        $expanded = $this->expandedIds();

        RolesMatrixView::remember([
            'roles' => $this->roles()->pluck('id')->all(),
            'search' => $this->search(),
            'expanded' => $expanded,
        ]);

        return $this->display($this->grid($expanded));
    }

    public function toggleSection()
    {
        $section = $this->section(request('section_id'));
        $expand = (bool) request('expand');

        $expanded = collect($this->expandedIds())
            ->reject(fn($id) => $id == $section->id)
            ->when($expand, fn($ids) => $ids->push($section->id))
            ->values()->all();

        RolesMatrixView::remember(['expanded' => $expanded]);

        return $this->display($this->sectionContent($section, $expand));
    }

    public function changeRolePermission()
    {
        abort_unless($this->canWrite, 403);

        $role = Role::findOrFail(request('role'));
        $permission = Permission::findOrFail(request('permission'));

        try {
            $result = $this->applyPermissionType($role, $permission, PermissionTypeEnum::tryFrom((int) request($role->id . '-' . $permission->id)));
        } catch (PermissionDependencyDeniedException $e) {
            return $this->cellsWithAlert($role, [$permission->id], $e->getMessage(), 'vlAlertError');
        }

        app(PermissionCacheInvalidator::class)->rolePermissionsChanged([$role->id]);

        if ($result->hasSideEffects()) {
            return $this->cellsWithAlert($role, [$permission->id, ...array_keys($result->changes)], $this->dependencySummary($result), 'vlAlertSuccess');
        }

        return $this->display($this->sectionHeader($this->section($permission->permission_section_id), true));
    }

    public function changeRolePermissionSection()
    {
        abort_unless($this->canWrite, 403);

        $role = Role::findOrFail(request('role'));
        $section = $this->section(request('permissionSection'));
        $requested = PermissionTypeEnum::tryFrom((int) request('type'));

        try {
            $result = DB::transaction(fn() => $this->visiblePermissions($section)->reduce(
                fn(PermissionDependencyResult $carry, $permission) => $carry->merge($this->applyPermissionType($role, $permission, $requested)),
                new PermissionDependencyResult,
            ));
        } catch (PermissionDependencyDeniedException $e) {
            return $this->cellsWithAlert($role, $this->visiblePermissions($section)->pluck('id')->all(), $e->getMessage(), 'vlAlertError');
        }

        app(PermissionCacheInvalidator::class)->rolePermissionsChanged([$role->id]);

        $outside = array_diff(array_keys($result->changes), $this->visiblePermissions($section)->pluck('id')->all());

        if ($outside || $result->skippedDenied) {
            return $this->cellsWithAlert($role, [...$this->visiblePermissions($section)->pluck('id'), ...array_keys($result->changes)], $this->dependencySummary($result), 'vlAlertSuccess');
        }

        return $this->display($this->sectionContent($section, in_array($section->id, $this->expandedIds())));
    }

    public function rememberWithRecommended()
    {
        RolesMatrixView::remember(['with_recommended' => (bool) request('with_recommended')]);
    }

    protected function roles()
    {
        return $this->roles ??= getRoles()->whereIn('id', array_filter((array) request('roles')))->values();
    }

    protected function search(): string
    {
        return trim((string) request('permission_name'));
    }

    protected function expandedIds(): array
    {
        if (request('restore')) {
            return RolesMatrixView::expanded();
        }

        $open = match (true) {
            (bool) request('collapse_all') => [],
            (bool) request('expand_all') => $this->visibleSections()->pluck('id')->all(),
            default => array_map('intval', array_keys((array) request('expanded'))),
        };

        $hidden = $this->sections()->pluck('id')->diff($this->visibleSections()->pluck('id'))->all();

        return array_values(array_unique([...$open, ...array_intersect(RolesMatrixView::expanded(), $hidden)]));
    }

    protected function sections()
    {
        return $this->sections ??= PermissionSection::get()
            ->each(fn($section) => $section->setRelation('permissions', $section->getPermissions()))
            ->filter(fn($section) => $section->permissions->isNotEmpty())
            ->sortBy(fn($section) => mb_strtolower((string) $section->name))
            ->values();
    }

    protected function visibleSections()
    {
        return $this->sections()->filter(fn($section) => $this->visiblePermissions($section)->isNotEmpty())->values();
    }

    protected function visiblePermissions($section)
    {
        return $section->permissions->filter(fn($permission) => $permission->matchesName($this->search()))->values();
    }

    protected function section($id)
    {
        return $this->sections()->firstWhere('id', $id) ?? abort(404);
    }

    protected function typeMap()
    {
        return $this->typeMap ??= \DB::table('permission_role')
            ->whereIn('role', $this->roles()->pluck('id'))
            ->get(['permission_id', 'role', 'permission_type'])
            ->groupBy('permission_id')
            ->map(fn($rows) => $rows->pluck('permission_type', 'role'));
    }

    protected function typeOf($role, $permission): ?int
    {
        $type = $this->typeMap()[$permission->id][$role->id] ?? null;

        return $type === null ? null : (int) $type;
    }

    protected function aggregate($role, $section): SectionAggregate
    {
        return new SectionAggregate($this->visiblePermissions($section)->map(fn($permission) => $this->typeOf($role, $permission)));
    }

    protected function roleStats($role): array
    {
        $types = $this->typeMap()
            ->map(fn($byRole) => $byRole[$role->id] ?? null)
            ->filter(fn($type) => $type !== null)
            ->map(fn($type) => (int) $type);

        $deny = $types->filter(fn($type) => $type === PermissionTypeEnum::DENY->value)->count();

        return ['access' => $types->count() - $deny, 'deny' => $deny];
    }

    protected function applyPermissionType($role, Permission $permission, ?PermissionTypeEnum $requested): PermissionDependencyResult
    {
        $capped = $requested ? $permission->capToSupportedType($requested) : null;
        $withRecommended = RolesMatrixView::withRecommended();

        return $capped
            ? $role->createOrUpdatePermission($permission->id, $capped, false, $withRecommended)
            : $role->removePermission($permission->id, false, $withRecommended);
    }

    /**
     * Dependencies may touch other sections: changed cells get their new state over the checkbox event bus and only the
     * affected section headers are refilled. A posted expanded[id] means loaded, not visible, so arrows resync from the DOM.
     */
    protected function cellsWithAlert($role, array $permissionIds, string $message, string $alertClass)
    {
        $this->typeMap = null;
        $permissions = Permission::whereIn('id', $permissionIds)->get();
        $loaded = array_map('intval', array_keys((array) request('expanded')));

        $headers = $this->visibleSections()->whereIn('id', $permissions->pluck('permission_section_id'))->map(fn($section) => response()->panel(
            $this->display($this->sectionHeader($section, in_array($section->id, $loaded))), $this->sectionHeadPanelId($section),
        ))->values()->all();

        return response()->kompoMulti([
            ...$headers,
            response()->kompoRun($this->syncCellsJs(), $permissions->mapWithKeys(fn($permission) => [
                $this->cellGroup($role, $permission) => $this->typeOf($role, $permission),
            ])),
            response()->kompoAlert('', options: ['alertClass' => $alertClass, 'message' => $message]),
        ]);
    }

    // The run payload arrives as ctx.response: buildJsCtx() overrides ctx.data with its reactive data helper.
    protected function syncCellsJs(): string
    {
        return <<<'JS'
            ({ response, $kompo }) => {
                Object.entries(response || {}).forEach(([group, value]) => $kompo.events.$emit('mscb:section-cascade:' + group, value));
                document.querySelectorAll('.roles-section').forEach(section => {
                    const body = section.querySelector('.roles-section-body');
                    section.querySelector('.roles-section-header').dataset.expanded = body && body.offsetParent ? '1' : '0';
                });
            }
        JS;
    }

    protected function dependencySummary(PermissionDependencyResult $result): string
    {
        $names = fn(array $ids) => Permission::whereIn('id', $ids)->get()
            ->map(fn($permission) => $permission->permission_name ?: $permission->permission_key)->implode(', ');

        return collect([
            'permissions-dependencies-also-granted' => array_diff($result->granted(), $result->grantedByRecommendation()),
            'permissions-dependencies-also-granted-recommended' => $result->grantedByRecommendation(),
            'permissions-dependencies-also-lowered' => $result->lowered(),
            'permissions-dependencies-also-removed' => $result->removed(),
            'permissions-dependencies-skipped-denied' => $result->skippedDenied,
        ])->filter()->map(fn($ids, $key) => __($key, ['list' => $names($ids)]))->implode(' ');
    }

    protected function display($element)
    {
        return $this->prepareOwnElementsForDisplay([$element])[0];
    }
}
