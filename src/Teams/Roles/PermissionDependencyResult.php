<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * Side effects of one role-permission change: other rows written because of dependencies,
 * and recommended parents left alone because the role denies them.
 */
final class PermissionDependencyResult
{
    /** @var array<int, array{from: ?PermissionTypeEnum, to: ?PermissionTypeEnum, recommended: bool}> recommended: reached only through a recommendation */
    public array $changes = [];

    /** @var list<int> */
    public array $skippedDenied = [];

    /** @var list<array{permission_id: int, required_permission_id: int}> */
    public array $conflicts = [];

    public function recordChange(int $permissionId, ?PermissionTypeEnum $from, ?PermissionTypeEnum $to, bool $recommended = false): void
    {
        $this->changes[$permissionId] = [
            'from' => $this->changes[$permissionId]['from'] ?? $from,
            'to' => $to,
            'recommended' => ($this->changes[$permissionId]['recommended'] ?? true) && $recommended,
        ];
    }

    public function recordSkippedDenied(int $permissionId): void
    {
        $this->skippedDenied = array_values(array_unique([...$this->skippedDenied, $permissionId]));
    }

    public function recordConflict(int $permissionId, int $requiredPermissionId): void
    {
        $this->conflicts[] = ['permission_id' => $permissionId, 'required_permission_id' => $requiredPermissionId];
    }

    public function granted(): array
    {
        return array_keys(array_filter($this->changes, fn($change) => $change['to'] && (!$change['from'] || $change['to']->value > $change['from']->value)));
    }

    public function grantedByRecommendation(): array
    {
        return array_values(array_filter($this->granted(), fn($permissionId) => $this->changes[$permissionId]['recommended']));
    }

    public function lowered(): array
    {
        return array_keys(array_filter($this->changes, fn($change) => $change['to'] && $change['from'] && $change['to']->value < $change['from']->value));
    }

    public function removed(): array
    {
        return array_keys(array_filter($this->changes, fn($change) => !$change['to']));
    }

    public function hasSideEffects(): bool
    {
        return (bool) ($this->changes || $this->skippedDenied || $this->conflicts);
    }

    public function merge(self $other): self
    {
        foreach ($other->changes as $permissionId => $change) {
            $this->recordChange($permissionId, $change['from'], $change['to'], $change['recommended']);
        }

        foreach ($other->skippedDenied as $permissionId) {
            $this->recordSkippedDenied($permissionId);
        }

        $this->conflicts = [...$this->conflicts, ...$other->conflicts];

        return $this;
    }
}
