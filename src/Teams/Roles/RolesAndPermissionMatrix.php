<?php

namespace Kompo\Auth\Teams\Roles;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\Role;
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

        $this->applyPermissionType($role, $permission, PermissionTypeEnum::tryFrom((int) request($role->id . '-' . $permission->id)));
        app(PermissionCacheInvalidator::class)->rolePermissionsChanged([$role->id]);

        return $this->display($this->sectionHeader($this->section($permission->permission_section_id), true));
    }

    public function changeRolePermissionSection()
    {
        abort_unless($this->canWrite, 403);

        $role = Role::findOrFail(request('role'));
        $section = $this->section(request('permissionSection'));
        $requested = PermissionTypeEnum::tryFrom((int) request('type'));

        $this->visiblePermissions($section)->each(fn($permission) => $this->applyPermissionType($role, $permission, $requested));
        app(PermissionCacheInvalidator::class)->rolePermissionsChanged([$role->id]);

        return $this->display($this->sectionContent($section, in_array($section->id, $this->expandedIds())));
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

    protected function applyPermissionType($role, Permission $permission, ?PermissionTypeEnum $requested): void
    {
        $capped = $requested ? $this->capToSupportedTypes($requested, $permission) : null;

        if (!$capped) {
            $role->permissions()->detach([$permission->id]);

            return;
        }

        $role->createOrUpdatePermission($permission->id, $capped, false);
    }

    protected function capToSupportedTypes(PermissionTypeEnum $requested, Permission $permission): ?PermissionTypeEnum
    {
        if ($permission->supportsType($requested)) {
            return $requested;
        }

        return collect([PermissionTypeEnum::ALL, PermissionTypeEnum::WRITE, PermissionTypeEnum::READ])
            ->first(fn($candidate) => $candidate->value < $requested->value && $permission->supportsType($candidate));
    }

    protected function display($element)
    {
        return $this->prepareOwnElementsForDisplay([$element])[0];
    }
}
