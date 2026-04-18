<?php

// src/Services/TeamHierarchyService.php
namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

/**
 * Dedicated service for efficiently handling team hierarchies
 */
class TeamHierarchyService implements TeamHierarchyInterface
{
    /**
     * Gets all descendant team IDs using recursive CTE (more efficient)
     */
    public function getDescendantTeamIds(int $teamId, ?string $search = null, ?int $maxDepth = null): Collection
    {
        return $this->executeDescendantsQuery($teamId, $search, $maxDepth);
    }

    /**
     * Verifies if a team is descendant of another (optimized)
     */
    public function isDescendant(int $parentTeamId, int $childTeamId): bool
    {
        if ($parentTeamId === $childTeamId) {
            return true;
        }

        return $this->executeIsDescendantQuery($parentTeamId, $childTeamId);
    }

    /**
     * Gets the complete hierarchy upwards (ancestors)
     */
    public function getAncestorTeamIds(int $teamId): Collection
    {
        return $this->executeAncestorsQuery($teamId);
    }

    /**
     * Gets teams from the same level (siblings)
     */
    public function getSiblingTeamIds(int $teamId, ?string $search = ''): Collection
    {
        return $this->executeSiblingsQuery($teamId, $search);
    }

    /**
     * Clears hierarchy cache (call when structure is modified)
     */
    public function clearCache(?int $teamId = null): void
    {
        // Cache invalidation belongs to CachedTeamHierarchyService.
    }

    /**
     * Optimized query using recursive CTE for descendants. (INCLUDES INITIAL PARENT TEAM ID)
     */
    private function executeDescendantsQuery(int $teamId, ?string $search = '', ?int $maxDepth = null): Collection
    {
        $maxDepthCondition = $maxDepth ? "AND depth < {$maxDepth}" : '';
        $searchCondition = $search ? ' WHERE LOWER(team_name) LIKE LOWER(?)' : '';

        $searchParam = $search ? "%{$search}%" : null;
        $bindings = array_values(array_filter([$teamId, $searchParam]));

        $sql = "
            WITH RECURSIVE team_hierarchy AS (
                -- Base case: the root team
                SELECT id, parent_team_id, team_name, 0 as depth
                FROM teams 
                WHERE id = ?
                
                UNION ALL
                
                -- Recursive case: children of already found teams
                SELECT t.id, t.parent_team_id, t.team_name, th.depth + 1
                FROM teams t
                INNER JOIN team_hierarchy th ON t.parent_team_id = th.id
                WHERE th.depth < 50 {$maxDepthCondition}
                  AND t.deleted_at IS NULL
            )
            SELECT id FROM team_hierarchy 
            {$searchCondition}
        ";

        return collect(DB::select($sql, $bindings))->pluck('id');
    }

    /**
     * Optimized query to verify if it's a descendant
     */
    private function executeIsDescendantQuery(int $parentTeamId, int $childTeamId): bool
    {
        $sql = "
            WITH RECURSIVE team_hierarchy AS (
                SELECT id, parent_team_id
                FROM teams 
                WHERE id = ?
                
                UNION ALL
                
                SELECT t.id, t.parent_team_id
                FROM teams t
                INNER JOIN team_hierarchy th ON t.parent_team_id = th.id
                WHERE th.id != ?  -- Avoid infinite loops
            )
            SELECT 1 FROM team_hierarchy WHERE id = ? LIMIT 1
        ";

        return !empty(DB::select($sql, [$parentTeamId, $childTeamId, $childTeamId]));
    }

    /**
     * Query to get ancestors (upwards in the hierarchy)
     */
    private function executeAncestorsQuery(int $teamId): Collection
    {
        $sql = "
            WITH RECURSIVE team_ancestors AS (
                SELECT id, parent_team_id, team_name, 0 as depth
                FROM teams 
                WHERE id = ?
                
                UNION ALL
                
                SELECT t.id, t.parent_team_id, t.team_name, ta.depth + 1
                FROM teams t
                INNER JOIN team_ancestors ta ON t.id = ta.parent_team_id
                WHERE ta.depth < 50
                  AND t.deleted_at IS NULL
            )
            SELECT id FROM team_ancestors ORDER BY depth DESC
        ";

        return collect(DB::select($sql, [$teamId]))->pluck('id');
    }

    /**
     * Query to get siblings (same parent_team_id)
     */
    private function executeSiblingsQuery(int $teamId, ?string $search = ''): Collection
    {
        $searchCondition = $search ? 'AND LOWER(t2.team_name) LIKE LOWER(?) ' : '';

        $sql = "
            SELECT t2.id
            FROM teams t1
            INNER JOIN teams t2 ON t1.parent_team_id = t2.parent_team_id
            WHERE t1.id = ? 
              AND t2.id != ? {$searchCondition}
              AND t2.deleted_at IS NULL
        ";

        $params = array_values(array_filter([$teamId, $teamId, $search ? wildcardSpace($search) : null]));

        return collect(DB::select($sql, $params))->pluck('id');
    }

    // NEW BATCH METHODS

    /**
     * Get ancestors for multiple teams in a single recursive query.
     */
    public function getBatchAncestorTeamIds(array $teamIds): Collection
    {
        $teamIds = collect($teamIds)->filter()->unique()->values()->all();

        if (empty($teamIds)) {
            return collect();
        }

        $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';
        $sql = "
            WITH RECURSIVE team_ancestors AS (
                SELECT id, parent_team_id, 0 as depth
                FROM teams
                WHERE id IN ({$placeholders})

                UNION ALL

                SELECT t.id, t.parent_team_id, ta.depth + 1
                FROM teams t
                INNER JOIN team_ancestors ta ON t.id = ta.parent_team_id
                WHERE ta.depth < 50
                  AND t.deleted_at IS NULL
            )
            SELECT DISTINCT id FROM team_ancestors
        ";

        return collect(DB::select($sql, $teamIds))->pluck('id');
    }

    /**
     * Get ancestors for multiple teams while preserving each target relationship.
     */
    public function getBatchAncestorTeamIdsByTarget(array $teamIds): Collection
    {
        $teamIds = collect($teamIds)->filter()->unique()->values()->all();

        if (empty($teamIds)) {
            return collect();
        }

        $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';

        $sql = "
            WITH RECURSIVE team_ancestors AS (
                SELECT id as target_team_id, id, parent_team_id, 0 as depth
                FROM teams
                WHERE id IN ({$placeholders})

                UNION ALL

                SELECT ta.target_team_id, t.id, t.parent_team_id, ta.depth + 1
                FROM teams t
                INNER JOIN team_ancestors ta ON t.id = ta.parent_team_id
                WHERE ta.depth < 50
                  AND t.deleted_at IS NULL
            )
            SELECT target_team_id, id
            FROM team_ancestors
            WHERE id != target_team_id
        ";

        return collect(DB::select($sql, $teamIds))
            ->groupBy('target_team_id')
            ->map(fn($rows) => $rows->pluck('id')->map(fn($id) => (int) $id)->values());
    }

    /**
     * Get descendants for multiple roots while preserving each root relationship.
     */
    public function getBatchDescendantTeamIdsByRoot(array $teamIds, ?string $search = ''): Collection
    {
        $teamIds = collect($teamIds)->filter()->unique()->values()->all();

        if (empty($teamIds)) {
            return collect();
        }

        $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';
        $searchCondition = $search ? 'AND LOWER(th.team_name) LIKE LOWER(?)' : '';

        $sql = "
            WITH RECURSIVE team_hierarchy AS (
                SELECT id, parent_team_id, team_name, id as root_team_id, 0 as depth
                FROM teams
                WHERE id IN ({$placeholders})

                UNION ALL

                SELECT t.id, t.parent_team_id, t.team_name, th.root_team_id, th.depth + 1
                FROM teams t
                INNER JOIN team_hierarchy th ON t.parent_team_id = th.id
                WHERE th.depth < 100
                  AND t.deleted_at IS NULL
            )
            SELECT th.id, th.root_team_id
            FROM team_hierarchy th
            WHERE th.id != th.root_team_id
            {$searchCondition}
        ";

        $params = array_merge(
            $teamIds,
            $search ? [wildcardSpace($search)] : []
        );

        return collect(DB::select($sql, $params))
            ->groupBy('root_team_id')
            ->map(fn($rows) => $rows->pluck('id')->values());
    }

    /**
     * Get siblings for multiple teams in a single query.
     */
    public function getBatchSiblingTeamIds(array $teamIds, ?string $search = ''): Collection
    {
        $teamIds = collect($teamIds)->filter()->unique()->values()->all();

        if (empty($teamIds)) {
            return collect();
        }

        $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';
        $searchCondition = $search ? 'AND LOWER(t2.team_name) LIKE LOWER(?)' : '';

        $sql = "
            SELECT DISTINCT t2.id
            FROM teams t1
            INNER JOIN teams t2 ON t1.parent_team_id = t2.parent_team_id
            WHERE t1.id IN ({$placeholders})
              AND t2.id NOT IN ({$placeholders})
              AND t2.deleted_at IS NULL
              {$searchCondition}
        ";

        $params = array_merge(
            $teamIds,
            $teamIds,
            $search ? [wildcardSpace($search)] : []
        );

        return collect(DB::select($sql, $params))->pluck('id');
    }

    /**
     * Get siblings for multiple source teams while preserving each source relationship.
     */
    public function getBatchSiblingTeamIdsBySource(array $teamIds, ?string $search = ''): Collection
    {
        $teamIds = collect($teamIds)->filter()->unique()->values()->all();

        if (empty($teamIds)) {
            return collect();
        }

        $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';
        $searchCondition = $search ? 'AND LOWER(t2.team_name) LIKE LOWER(?)' : '';

        $sql = "
            SELECT DISTINCT t2.id, t1.id as source_team_id
            FROM teams t1
            INNER JOIN teams t2 ON t1.parent_team_id = t2.parent_team_id
            WHERE t1.id IN ({$placeholders})
              AND t2.id NOT IN ({$placeholders})
              AND t2.deleted_at IS NULL
            {$searchCondition}
        ";

        $params = array_merge(
            $teamIds,
            $teamIds,
            $search ? [wildcardSpace($search)] : []
        );

        return collect(DB::select($sql, $params))
            ->groupBy('source_team_id')
            ->map(fn($rows) => $rows->pluck('id')->values());
    }
}
