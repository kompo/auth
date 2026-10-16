<?php

namespace Kompo\Auth\Teams\Roles;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionDependencyTypeEnum;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Teams\Cache\PermissionCacheInvalidator;

/**
 * Keeps role grants consistent with permission_dependencies.
 * Granting a permission grants what it requires; lowering or removing it lowers or removes what depends on it.
 * Structural edges always apply at the child's level; recommended edges only when asked, as READ.
 */
class PermissionDependencyService
{
    protected ?Collection $edges = null;
    protected ?Collection $permissions = null;

    /**
     * Sets one role-permission row and propagates it. A null or DENY type removes the dependents.
     *
     * @throws PermissionDependencyDeniedException when a structural requirement is denied on the role
     */
    public function apply(string $roleId, int $permissionId, ?PermissionTypeEnum $type, bool $withRecommended = false): PermissionDependencyResult
    {
        return DB::transaction(function () use ($roleId, $permissionId, $type, $withRecommended) {
            $current = $this->currentTypes($roleId);
            $result = new PermissionDependencyResult;

            if ($type && $type !== PermissionTypeEnum::DENY) {
                $this->grantRequired($roleId, $permissionId, $type, $withRecommended, $current, $result, true);
            }

            $this->write($roleId, $permissionId, $type, $current);

            $grantLevel = $type === PermissionTypeEnum::DENY ? null : $type;
            $this->restrictDependents($roleId, $permissionId, $grantLevel, $withRecommended, $current, $result);

            return $result;
        });
    }

    /**
     * Replaces the structural edges of every listed permission and adds missing recommended ones.
     * Recommended edges are never removed here: admins edit them in the permission info modal.
     *
     * @param array<int, list<int>> $structural
     * @param array<int, list<int>> $recommended
     */
    public function syncDeclared(array $structural, array $recommended = []): void
    {
        $this->assertAcyclic($structural);

        DB::transaction(function () use ($structural, $recommended) {
            foreach ($structural as $permissionId => $requiredIds) {
                $requiredIds = array_values(array_unique(array_map('intval', $requiredIds)));

                DB::table('permission_dependencies')
                    ->where('permission_id', $permissionId)
                    ->where('dependency_type', PermissionDependencyTypeEnum::STRUCTURAL->value)
                    ->whereNotIn('required_permission_id', $requiredIds)
                    ->delete();

                foreach ($requiredIds as $requiredId) {
                    $this->upsertEdge($permissionId, $requiredId, PermissionDependencyTypeEnum::STRUCTURAL);
                }
            }

            foreach ($recommended as $permissionId => $requiredIds) {
                foreach (array_unique(array_map('intval', $requiredIds)) as $requiredId) {
                    $exists = DB::table('permission_dependencies')
                        ->where('permission_id', $permissionId)->where('required_permission_id', $requiredId)->exists();

                    if (!$exists) {
                        $this->upsertEdge($permissionId, $requiredId, PermissionDependencyTypeEnum::RECOMMENDED);
                    }
                }
            }
        });

        $this->edges = null;
    }

    /**
     * Grants missing structural requirements on every role. Denied requirements are reported, never overridden.
     *
     * @return array<string, PermissionDependencyResult> keyed by role id, only roles with side effects
     */
    public function backfillStructural(): array
    {
        $childIds = $this->edges()
            ->where('dependency_type', PermissionDependencyTypeEnum::STRUCTURAL->value)
            ->pluck('permission_id')->unique()->all();

        $roleIds = DB::table('permission_role')->whereNull('deleted_at')
            ->whereIn('permission_id', $childIds)->distinct()->pluck('role');

        $report = DB::transaction(fn() => $roleIds->mapWithKeys(function ($roleId) use ($childIds) {
            $current = $this->currentTypes($roleId);
            $result = new PermissionDependencyResult;

            foreach (array_intersect(array_keys($current), $childIds) as $permissionId) {
                $type = $current[$permissionId];

                if ($type !== PermissionTypeEnum::DENY) {
                    $this->grantRequired($roleId, $permissionId, $type, false, $current, $result, false);
                }
            }

            return [$roleId => $result];
        })->filter(fn($result) => $result->hasSideEffects())->all());

        if ($report) {
            app(PermissionCacheInvalidator::class)->rolePermissionsChanged(array_keys($report));
        }

        return $report;
    }

    /**
     * Whether a change on this permission can touch other rows, so the matrix knows to refresh the whole grid.
     */
    public function hasDependencies(int $permissionId, bool $withRecommended = false): bool
    {
        return $this->edges()
            ->filter(fn($edge) => $withRecommended || $edge->dependency_type == PermissionDependencyTypeEnum::STRUCTURAL->value)
            ->contains(fn($edge) => $edge->permission_id == $permissionId || $edge->required_permission_id == $permissionId);
    }

    protected function grantRequired(string $roleId, int $permissionId, PermissionTypeEnum $type, bool $withRecommended, array &$current, PermissionDependencyResult $result, bool $refuseDenied): void
    {
        $needed = [];
        $queue = [[$permissionId, $type, true]];

        while ($queue) {
            [$childId, $childType, $strictPath] = array_shift($queue);

            foreach ($this->requiredOf($childId, $withRecommended) as $edge) {
                $parentId = (int) $edge->required_permission_id;
                $parent = $this->permission($parentId);

                if (!$parent || $parentId === $permissionId) {
                    continue;
                }

                $isStructural = $edge->dependency_type == PermissionDependencyTypeEnum::STRUCTURAL->value;
                $strict = $strictPath && $isStructural;

                if (($current[$parentId] ?? null) === PermissionTypeEnum::DENY) {
                    if ($strict && $refuseDenied) {
                        throw new PermissionDependencyDeniedException($this->permission($permissionId), $parent);
                    }

                    $strict ? $result->recordConflict($childId, $parentId) : $result->recordSkippedDenied($parentId);

                    continue;
                }

                $level = $parent->capToSupportedType($isStructural ? $childType : PermissionTypeEnum::READ);
                $known = $needed[$parentId] ?? null;

                if (!$level || ($known && PermissionTypeEnum::hasPermission($known['type'], $level) && ($known['strict'] || !$strict))) {
                    continue;
                }

                $needed[$parentId] = [
                    'type' => $known ? PermissionTypeEnum::from($known['type']->value | $level->value) : $level,
                    'strict' => ($known['strict'] ?? false) || $strict,
                ];

                $queue[] = [$parentId, $needed[$parentId]['type'], $needed[$parentId]['strict']];
            }
        }

        foreach ($needed as $parentId => $need) {
            $have = $current[$parentId] ?? null;

            if ($have && PermissionTypeEnum::hasPermission($have, $need['type'])) {
                continue;
            }

            $this->write($roleId, $parentId, $need['type'], $current);
            $result->recordChange($parentId, $have, $need['type'], !$need['strict']);
        }
    }

    protected function restrictDependents(string $roleId, int $permissionId, ?PermissionTypeEnum $level, bool $withRecommended, array &$current, PermissionDependencyResult $result): void
    {
        $queue = [[$permissionId, $level]];

        while ($queue) {
            [$parentId, $parentLevel] = array_shift($queue);
            $parent = $this->permission($parentId);

            foreach ($this->dependentsOf($parentId, $withRecommended) as $edge) {
                $childId = (int) $edge->permission_id;
                $have = $current[$childId] ?? null;

                if ($childId === $permissionId || !$have || $have === PermissionTypeEnum::DENY) {
                    continue;
                }

                $isStructural = $edge->dependency_type == PermissionDependencyTypeEnum::STRUCTURAL->value;
                $allowed = $this->allowedChildType($have, $parent, $parentLevel, $isStructural);

                if ($allowed === $have) {
                    continue;
                }

                $this->write($roleId, $childId, $allowed, $current);
                $result->recordChange($childId, $have, $allowed);
                $queue[] = [$childId, $allowed];
            }
        }
    }

    /**
     * Highest type the child may keep under its parent's level: same level for structural, any for recommended.
     */
    protected function allowedChildType(PermissionTypeEnum $have, ?Permission $parent, ?PermissionTypeEnum $parentLevel, bool $isStructural): ?PermissionTypeEnum
    {
        if (!$parentLevel) {
            return null;
        }

        if (!$isStructural || !$parent) {
            return $have;
        }

        return collect([PermissionTypeEnum::ALL, PermissionTypeEnum::WRITE, PermissionTypeEnum::READ])
            ->filter(fn($candidate) => $candidate->value <= $have->value)
            ->first(fn($candidate) => PermissionTypeEnum::hasPermission($parentLevel, $parent->capToSupportedType($candidate) ?? $candidate));
    }

    protected function write(string $roleId, int $permissionId, ?PermissionTypeEnum $type, array &$current): void
    {
        $rows = DB::table('permission_role')->where('role', $roleId)->where('permission_id', $permissionId);

        if (!$type) {
            $rows->delete();
            unset($current[$permissionId]);

            return;
        }

        $updated = (clone $rows)->update([
            'permission_type' => $type->value,
            'modified_by' => auth()->id(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        if (!$updated) {
            DB::table('permission_role')->insert([
                'role' => $roleId,
                'permission_id' => $permissionId,
                'permission_type' => $type->value,
                'added_by' => auth()->id(),
                'modified_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $current[$permissionId] = $type;
    }

    protected function currentTypes(string $roleId): array
    {
        return DB::table('permission_role')->where('role', $roleId)->whereNull('deleted_at')
            ->pluck('permission_type', 'permission_id')
            ->map(fn($type) => PermissionTypeEnum::from((int) $type))
            ->all();
    }

    protected function upsertEdge(int $permissionId, int $requiredId, PermissionDependencyTypeEnum $type): void
    {
        $edge = DB::table('permission_dependencies')->where('permission_id', $permissionId)->where('required_permission_id', $requiredId);

        if ((clone $edge)->exists()) {
            $edge->where('dependency_type', '!=', $type->value)->update(['dependency_type' => $type->value, 'updated_at' => now()]);

            return;
        }

        DB::table('permission_dependencies')->insert([
            'permission_id' => $permissionId,
            'required_permission_id' => $requiredId,
            'dependency_type' => $type->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<int, list<int>> $structural
     */
    protected function assertAcyclic(array $structural): void
    {
        $graph = $this->edges()
            ->where('dependency_type', PermissionDependencyTypeEnum::STRUCTURAL->value)
            ->reject(fn($edge) => array_key_exists($edge->permission_id, $structural))
            ->groupBy('permission_id')
            ->map(fn($edges) => $edges->pluck('required_permission_id')->map(fn($id) => (int) $id)->all())
            ->all();

        foreach ($structural as $permissionId => $requiredIds) {
            $graph[$permissionId] = array_map('intval', $requiredIds);
        }

        $state = [];
        $visit = function (int $node, array $path) use (&$visit, &$state, $graph) {
            if (($state[$node] ?? null) === 'done') {
                return;
            }

            if (($state[$node] ?? null) === 'open') {
                $cycle = [...array_slice($path, array_search($node, $path)), $node];
                throw new \LogicException('Circular structural permission dependency: ' . collect($cycle)->map(fn($id) => $this->permission($id)?->permission_key ?? $id)->implode(' -> '));
            }

            $state[$node] = 'open';

            foreach ($graph[$node] ?? [] as $requiredId) {
                $visit($requiredId, [...$path, $node]);
            }

            $state[$node] = 'done';
        };

        foreach (array_keys($graph) as $node) {
            $visit((int) $node, []);
        }
    }

    protected function requiredOf(int $permissionId, bool $withRecommended): Collection
    {
        return $this->edgesOfType($withRecommended)->where('permission_id', $permissionId);
    }

    protected function dependentsOf(int $permissionId, bool $withRecommended): Collection
    {
        return $this->edgesOfType($withRecommended)->where('required_permission_id', $permissionId);
    }

    protected function edgesOfType(bool $withRecommended): Collection
    {
        return $withRecommended
            ? $this->edges()
            : $this->edges()->where('dependency_type', PermissionDependencyTypeEnum::STRUCTURAL->value);
    }

    protected function edges(): Collection
    {
        return $this->edges ??= DB::table('permission_dependencies')
            ->get(['permission_id', 'required_permission_id', 'dependency_type']);
    }

    protected function permission(int $permissionId): ?Permission
    {
        $this->permissions ??= Permission::query()->get()->keyBy('id');

        return $this->permissions[$permissionId] ?? null;
    }
}
