<?php

namespace Kompo\Auth\Commands;

use Illuminate\Console\Command;
use Kompo\Auth\Models\Teams\TeamRole;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;

class CleanupRedundantHierarchyRoles extends Command
{
    protected $signature = 'auth:cleanup-hierarchy-roles {--dry-run : Show what would be deleted without actually deleting}';
    protected $description = 'Remove redundant hierarchy roles that are causing memory issues';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        $this->info('Analyzing redundant hierarchy roles...');
        
        // Find users with excessive roles
        $usersWithManyRoles = \DB::select("
            SELECT user_id, COUNT(*) as role_count
            FROM team_roles 
            WHERE deleted_at IS NULL 
                AND terminated_at IS NULL 
                AND suspended_at IS NULL
            GROUP BY user_id 
            HAVING role_count > 100
            ORDER BY role_count DESC
        ");
        
        $this->table(['User ID', 'Role Count'], array_map(fn($u) => [$u->user_id, $u->role_count], $usersWithManyRoles));
        
        $totalRedundant = 0;
        
        foreach ($usersWithManyRoles as $userData) {
            $redundant = $this->findRedundantRoles($userData->user_id, $isDryRun);
            $totalRedundant += $redundant;
            
            $this->info("User {$userData->user_id}: Found {$redundant} redundant roles");
        }
        
        if ($isDryRun) {
            $this->warn("DRY RUN: Would delete {$totalRedundant} redundant roles");
            $this->info("Run without --dry-run to actually delete them");
        } else {
            $this->info("✅ Deleted {$totalRedundant} redundant roles");
        }
    }
    
    private function findRedundantRoles(int $userId, bool $isDryRun): int
    {
        // Get all roles for user with hierarchy info
        $userRoles = TeamRole::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereNull('terminated_at') 
            ->whereNull('suspended_at')
            ->with(['team'])
            ->get();
            
        $redundantRoles = collect();
        
        // Group by role type
        $roleGroups = $userRoles->groupBy('role');

        foreach ($roleGroups as $roleName => $roles) {
            $redundantRoles = $redundantRoles->merge($this->findRedundantInGroup($roles));
        }
        
        $redundantCount = $redundantRoles->count();
        
        if (!$isDryRun && $redundantCount > 0) {
            // Delete redundant roles in chunks to avoid memory issues
            $redundantRoles->chunk(100)->each(function($chunk) {
                TeamRole::whereIn('id', $chunk->pluck('id'))->delete();
            });
        }
        
        return $redundantCount;
    }
    
    private function findRedundantInGroup($roles): \Illuminate\Support\Collection
    {
        $redundant = collect();
        $hierarchyRoles = collect();
        $directRoles = collect();
        
        // Separate hierarchy roles from direct roles
        foreach ($roles as $role) {
            if ($role->role_hierarchy === RoleHierarchyEnum::DIRECT) {
                $directRoles->push($role);
            } else {
                $hierarchyRoles->push($role);
            }
        }
        
        // If user has hierarchy roles, check which direct roles are redundant
        foreach ($hierarchyRoles as $hierarchyRole) {
            $accessibleTeams = $this->getHierarchyAccessibleTeams($hierarchyRole);
            
            foreach ($directRoles as $directRole) {
                if ($accessibleTeams->contains($directRole->team_id)) {
                    $redundant->push($directRole);
                }
            }
        }
        
        return $redundant;
    }
    
    private function getHierarchyAccessibleTeams(TeamRole $role): \Illuminate\Support\Collection
    {
        $teams = collect([$role->team_id]);
        
        if ($role->getRoleHierarchyAccessBelow()) {
            // Get descendant teams
            $descendants = $this->getDescendantTeamIds($role->team_id);
            $teams = $teams->merge($descendants);
        }
        
        if ($role->getRoleHierarchyAccessNeighbors()) {
            // Get sibling teams
            $siblings = $this->getSiblingTeamIds($role->team_id);
            $teams = $teams->merge($siblings);
        }
        
        return $teams->unique();
    }
    
    private function getDescendantTeamIds(int $teamId): \Illuminate\Support\Collection
    {
        // Use raw SQL to avoid memory issues
        $descendants = \DB::select("
            WITH RECURSIVE team_hierarchy AS (
                SELECT id, parent_team_id FROM teams WHERE id = ?
                UNION ALL
                SELECT t.id, t.parent_team_id 
                FROM teams t
                INNER JOIN team_hierarchy th ON t.parent_team_id = th.id
                WHERE th.id != ?
            )
            SELECT id FROM team_hierarchy WHERE id != ?
        ", [$teamId, $teamId, $teamId]);
        
        return collect($descendants)->pluck('id');
    }
    
    private function getSiblingTeamIds(int $teamId): \Illuminate\Support\Collection
    {
        $siblings = \DB::select("
            SELECT t2.id
            FROM teams t1
            INNER JOIN teams t2 ON t1.parent_team_id = t2.parent_team_id
            WHERE t1.id = ? AND t2.id != ?
        ", [$teamId, $teamId]);
        
        return collect($siblings)->pluck('id');
    }
}