<?php

namespace Kompo\Auth\Teams;

class HierarchyNodeContext
{
    public function __construct(
        public readonly string $id,
        public readonly int $teamId,
        public readonly ?int $parentId,
        public readonly string $teamName,
        public readonly ?string $parentName,
        public readonly bool $isCurrent,
        public readonly bool $isInCurrentPath,
        public readonly bool $isCommittee,
        public readonly bool $isSelectable,
        public readonly bool $hasChildren,
        public readonly int $childrenCount,
        public readonly int $committeeCount,
        public readonly ?string $levelLabel,
        public readonly ?string $levelClass,
        public readonly array $roles,
        public readonly ?array $switchRole,
    ) {}

    public function hasSwitchableRole(): bool
    {
        return !empty($this->roles) || $this->switchRole !== null;
    }

    public function isVisible(): bool
    {
        if ($this->isSelectable) {
            return $this->hasSwitchableRole() || $this->hasChildren;
        }

        return $this->hasChildren;
    }

    public function switchOnlyRole(): ?array
    {
        if ($this->switchRole === null) {
            return null;
        }

        foreach ($this->roles as $role) {
            if (($role['id'] ?? null) === ($this->switchRole['id'] ?? null)) {
                return null;
            }
        }

        return $this->switchRole;
    }
}
