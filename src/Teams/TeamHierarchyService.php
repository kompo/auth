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
    public function getDescendantTeamsWithRole(int $teamId, string $role, ?string $search = ''): Collection
    {
        $cacheKey = "descendants_with_role.{$teamId}.{$role}." . md5($search);
        
        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL / 2, function () use ($teamId, $role, $search) {
                return $this->executeDescendantsWithRoleQuery($teamId, $role, $search);
            });    }

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
            });    }

    /**
     * Gets the complete hierarchy upwards (ancestors)
     */
    public function getAncestorTeamIds(int $teamId): Collection
    {
        $cacheKey = "ancestors.{$teamId}";
        
        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function () use ($teamId) {
                return $this->executeAncestorsQuery($teamId);
            });    }

    /**
     * Gets teams from the same level (siblings)
     */
    public function getSiblingTeamIds(int $teamId, ?string $search = ''): Collection
    {
        $cacheKey = "siblings.{$teamId}";
        
        return Cache::rememberWithTags([self::CACHE_TAG], $cacheKey, self::CACHE_TTL, function () use ($teamId, $search) {
                return $this->executeSiblingsQuery($teamId, $search);
            });    }

    /**
     * Clears hierarchy cache (call when structure is modified)
     */
    public function clearCache(?int $teamId = null): void
    {        if ($teamId) {
            // Clear specific team cache and its related ones
            $patterns = [
                "descendants.{$teamId}.*",
                "ancestors.{$teamId}",
                "siblings.{$teamId}",
                "is_descendant.{$teamId}.*",
                "is_descendant.*.{$teamId}",
            ];
            
            foreach ($patterns as $pattern) {
                Cache::forgetTagsPattern([self::CACHE_TAG], $pattern);            }
        } else {
            // Clear all hierarchy cache
            Cache::flushTags([self::CACHE_TAG]);
        }    }

    /**
     * Optimized query using recursive CTE for descendants
     */
    private function executeDescendantsQuery(int $teamId, ?string $search = '', ?int $maxDepth = null): Collection
    {
        $maxDepthCondition = $maxDepth ? "AND depth < {$maxDepth}" : '';
        $searchCondition = $search ? "and team_name LIKE %{$search}%" : '';

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
                WHERE th.depth < 50 {$maxDepthCondition} {$searchCondition}
                  AND t.deleted_at IS NULL
            )
            SELECT id FROM team_hierarchy
        ";

        return collect(DB::select($sql, [$teamId]))->pluck('id');    }

    /**
     * Optimized query for descendants with specific role
     */
    private function executeDescendantsWithRoleQuery(int $teamId, string $role, ?string $search = ''): Collection
    {
        $searchCondition = $search ? "AND t.team_name LIKE ?" : '';
        $params = [$teamId, $role];
        if ($search) {
            $params[] = "%{$search}%";
        }

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
                  {$searchCondition}
            )
            SELECT th.id, ? as role
            FROM team_hierarchy th
        ";

        return collect(DB::select($sql, $params))->mapWithKeys(fn($row) => [$row->id => $row->role]);    }

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

        return !empty(DB::select($sql, [$parentTeamId, $childTeamId, $childTeamId]));    }

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

        return collect(DB::select($sql, [$teamId]))->pluck('id');    }

    /**
     * Query to get siblings (same parent_team_id)
     */
    private function executeSiblingsQuery(int $teamId, ?string $search = ''): Collection
    {
        $searchCondition = $search ? 'AND team_name LIKE %{$search}% ' : '';

        $sql = "
            SELECT t2.id
            FROM teams t1
            INNER JOIN teams t2 ON t1.parent_team_id = t2.parent_team_id
            WHERE t1.id = ? 
              AND t2.id != ? {$searchCondition}
              AND t2.deleted_at IS NULL
        ";

        return collect(DB::select($sql, [$teamId, $teamId]))->pluck('id');
    }
}