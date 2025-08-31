<?php

// src/Services/TeamHierarchyService.php
namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Dedicated service for efficiently handling team hierarchies
 */
class TeamHierarchyService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_TAG = 'team-hierarchy';

    /**
     * Gets all descendant team IDs using recursive CTE (more efficient)
     */
    public function getDescendantTeamIds(int $teamId, ?string $search = null, ?int $maxDepth = null): Collection
    {
        $cacheKey = "descendants.{$teamId}.{$maxDepth}" . md5($search);

        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function () use ($teamId, $search, $maxDepth) {
            return $this->executeDescendantsQuery($teamId, $search, $maxDepth);
        });
    }

    /**
     * Gets descendants with assigned roles (for role switcher)
     */
    public function getDescendantTeamsWithRole(int $teamId, string $role, ?string $search = '', $limit = null): Collection
    {
        $cacheKey = "descendants_with_role.{$teamId}.{$role}." . md5($search) . ($limit ? ".{$limit}" : '');

        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL / 2, function () use ($teamId, $role, $search, $limit) {
            return $this->executeDescendantsWithRoleQuery($teamId, $role, $search, $limit);
        });
    }

    /**
     * Verifies if a team is descendant of another (optimized)
     */
    public function isDescendant(int $parentTeamId, int $childTeamId): bool
    {
        if ($parentTeamId === $childTeamId) {
            return true;
        }

        $cacheKey = "is_descendant.{$parentTeamId}.{$childTeamId}";

        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function () use ($parentTeamId, $childTeamId) {
            return $this->executeIsDescendantQuery($parentTeamId, $childTeamId);
        });
    }

    /**
     * Gets the complete hierarchy upwards (ancestors)
     */
    public function getAncestorTeamIds(int $teamId): Collection
    {
        $cacheKey = "ancestors.{$teamId}";

        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function () use ($teamId) {
            return $this->executeAncestorsQuery($teamId);
        });
    }

    /**
     * Gets teams from the same level (siblings)
     */
    public function getSiblingTeamIds(int $teamId, ?string $search = '', $limit = null): Collection
    {
        $cacheKey = "siblings.{$teamId}." . md5($search) . ($limit ? ".{$limit}" : '');

        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function () use ($teamId, $search, $limit) {
            return $this->executeSiblingsQuery($teamId, $search, $limit);
        });
    }

    /**
     * Clears hierarchy cache (call when structure is modified)
     */
    public function clearCache(?int $teamId = null): void
    {
        if ($teamId) {
            Cache::flushTags(['teams.' . $teamId]);

            // Clear specific team cache and its related ones
            $patterns = [
                "descendants.{$teamId}.*",
                "ancestors.{$teamId}",
                "siblings.{$teamId}",
                "is_descendant.{$teamId}.*",
                "is_descendant.*.{$teamId}",
            ];

            foreach ($patterns as $pattern) {
                Cache::forgetTagsPattern([self::CACHE_TAG], $pattern);
            }
        } else {
            // Clear all hierarchy cache
            Cache::flushTags([self::CACHE_TAG]);
        }
    }

    /**
     * Optimized query using recursive CTE for descendants
     */
    private function executeDescendantsQuery(int $teamId, ?string $search = '', ?int $maxDepth = null): Collection
    {
        $maxDepthCondition = $maxDepth ? "AND depth < {$maxDepth}" : '';
        $searchCondition = $search ? ' WHERE LOWER(teams.team_name) LIKE LOWER(?)' : '';

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
     * Optimized query for descendants with specific role
     */
    private function executeDescendantsWithRoleQuery(int $teamId, string $role, ?string $search = '', $limit = null): Collection
    {
        $searchCondition = $search ? "AND th.team_name LIKE ?" : '';

        $limitQuery = $limit ? "LIMIT ?" : '';
        $searchParam = $search ? "%{$search}%" : null;
        $params = array_values(array_filter([$teamId, $role, $limit, $searchParam]));

        $sql = "
            WITH RECURSIVE team_hierarchy AS (
                SELECT id, parent_team_id, team_name, 0 as depth
                FROM teams 
                WHERE id = ?
                
                UNION ALL
                
                SELECT t.id, t.parent_team_id, t.team_name, th.depth + 1
                FROM teams t
                INNER JOIN team_hierarchy th ON t.parent_team_id = th.id
                WHERE th.depth < 50
                  AND t.deleted_at IS NULL
            )
            SELECT th.id, ? as role
            FROM team_hierarchy th 
            {$limitQuery} {$searchCondition}
        ";

        return collect(DB::select($sql, $params))->mapWithKeys(fn($row) => [$row->id => $row->role]);
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
    private function executeSiblingsQuery(int $teamId, ?string $search = '', $limit = null): Collection
    {
        $searchCondition = $search ? 'AND LOWER(t2.team_name) LIKE LOWER(?) ' : '';
        $limitCondition = $limit ? "LIMIT {$limit}" : '';

        $sql = "
            SELECT t2.id
            FROM teams t1
            INNER JOIN teams t2 ON t1.parent_team_id = t2.parent_team_id
            WHERE t1.id = ? 
              AND t2.id != ? {$searchCondition}
              AND t2.deleted_at IS NULL
            {$limitCondition}
        ";

        $params = array_values(array_filter([$teamId, $teamId, $search ? $search : null, $limit]));

        return collect(DB::select($sql, $params))->pluck('id');
    }

    // NEW BATCH METHODS

    /**
     * Get descendants for multiple teams in a single query (batch operation)
     */
    public function getBatchDescendantTeamsWithRoles(array $teamIdsWithRoles, ?string $search = '', $limit = null): Collection
    {
        if (empty($teamIdsWithRoles)) {
            return collect();
        }

        return $this->executeBatchDescendantsWithRolesQuery($teamIdsWithRoles, $search, $limit);
    }

    /**
     * Get siblings for multiple teams in a single query (batch operation)
     */
    public function getBatchSiblingTeamsWithRoles(array $teamIdsWithRoles, ?string $search = '', $limit = null): Collection
    {
        if (empty($teamIdsWithRoles)) {
            return collect();
        }

        return $this->executeBatchSiblingsWithRolesQuery($teamIdsWithRoles, $search, $limit);
    }

    /**
     * Batch query for descendants with roles using recursive CTE
     */
    private function executeBatchDescendantsWithRolesQuery(array $teamIdsWithRoles, ?string $search = '', $limit = null): Collection
    {
        $searchCondition = $search ? 'AND LOWER(th.team_name) LIKE LOWER(?)' : '';
        $limitQuery = $limit ? "LIMIT ?" : '';
        
        // Create placeholders for IN clause
        $teamIdPlaceholders = str_repeat('?,', count($teamIdsWithRoles) - 1) . '?';
        $teamIds = array_keys($teamIdsWithRoles);

        $sql = "
            WITH RECURSIVE team_hierarchy AS (
                -- Base case: all root teams we're interested in
                SELECT id, parent_team_id, team_name, id as root_team_id, 0 as depth
                FROM teams 
                WHERE id IN ({$teamIdPlaceholders}) 
                
                UNION ALL
                
                -- Recursive case: children of already found teams
                SELECT t.id, t.parent_team_id, t.team_name, th.root_team_id, th.depth + 1
                FROM teams t
                INNER JOIN team_hierarchy th ON t.parent_team_id = th.id
                WHERE th.depth < 100
                AND t.deleted_at IS NULL
            )
            SELECT th.id, th.root_team_id
            FROM team_hierarchy th 
            WHERE th.id != th.root_team_id  -- Exclude the root teams themselves 
            {$searchCondition} 
            {$limitQuery}
        ";

        $searchParam = $search ? [wildcardSpace($search)] : [];
        $params = array_values(array_filter(array_merge($teamIds, $searchParam, $limit ? [$limit] : [])));
        $results = collect(DB::select($sql, $params));

        // Map results back to team_id => role format
        return $results->mapWithKeys(function($row) use ($teamIdsWithRoles) {
            $rootTeamId = $row->root_team_id;
            $role = $teamIdsWithRoles[$rootTeamId] ?? null;
            return $role ? [$row->id => $role] : [];
        });
    }

    /**
     * Batch query for siblings with roles
     */
    private function executeBatchSiblingsWithRolesQuery(array $teamIdsWithRoles, ?string $search = '', $limit = null): Collection
    {
        $searchCondition = $search ? 'AND LOWER(t2.team_name) LIKE LOWER(?)' : '';
        $limitQuery = $limit ? "LIMIT ?" : '';
        
        $teamIdPlaceholders = str_repeat('?,', count($teamIdsWithRoles) - 1) . '?';
        $teamIds = array_keys($teamIdsWithRoles);

        $sql = "
            SELECT t2.id, t1.id as source_team_id
            FROM teams t1
            INNER JOIN teams t2 ON t1.parent_team_id = t2.parent_team_id
            WHERE t1.id IN ({$teamIdPlaceholders})
            AND t2.id NOT IN ({$teamIdPlaceholders})  -- Exclude source teams
            AND t2.deleted_at IS NULL
            {$searchCondition}
            {$limitQuery}
        ";

        $searchParam = $search ? [wildcardSpace($search)] : [];
        $params = array_values(array_filter(array_merge($teamIds, $teamIds, $searchParam, $limit ? [$limit] : [])));
        $results = collect(DB::select($sql, $params));

        // Map results back to team_id => role format
        return $results->mapWithKeys(function($row) use ($teamIdsWithRoles) {
            $sourceTeamId = $row->source_team_id;
            $role = $teamIdsWithRoles[$sourceTeamId] ?? null;
            return $role ? [$row->id => $role] : [];
        });
    }
}
