<?php

// database/migrations/2025_06_02_000001_optimize_permission_indexes.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Add critical indexes for permission resolution
        Schema::table('team_roles', function (Blueprint $table) {
            // Composite index for user permission queries
            $table->index(['user_id', 'terminated_at', 'suspended_at'], 'team_roles_active_user_idx');
            
            // Index for team hierarchy access
            $table->index(['team_id', 'role_hierarchy'], 'team_roles_team_hierarchy_idx');
            
            // Index for role-based filtering
            $table->index(['role', 'deleted_at'], 'team_roles_role_active_idx');
        });

        Schema::table('permission_role', function (Blueprint $table) {
            // Composite index for role permission lookups
            $table->index(['role', 'permission_type'], 'permission_role_type_idx');
        });

        Schema::table('permission_team_role', function (Blueprint $table) {
            // Composite index for team role permission lookups
            $table->index(['team_role_id', 'permission_type'], 'permission_team_role_type_idx');
        });

        Schema::table('permissions', function (Blueprint $table) {
            // Index for permission key lookups (if not already unique)
            if (!$this->hasUniqueIndex('permissions', 'permission_key')) {
                $table->unique(['permission_key'], 'permissions_key_unique');
            }
            
            // Index for section-based queries
            $table->index(['permission_section_id', 'deleted_at'], 'permissions_section_active_idx');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('inactive_at')->nullable();
        });

        // Optimize team hierarchy queries
        Schema::table('teams', function (Blueprint $table) {
            // Composite index for hierarchy traversal
            $table->index(['parent_team_id', 'deleted_at', 'inactive_at'], 'teams_hierarchy_active_idx');
            
            // Index for team name searches in hierarchy
            $table->index(['team_name', 'deleted_at'], 'teams_name_active_idx');
        });
    }

    public function down()
    {
        // Drop indexes
        Schema::table('team_roles', function (Blueprint $table) {
            $table->dropIndex('team_roles_active_user_idx');
            $table->dropIndex('team_roles_team_hierarchy_idx');
            $table->dropIndex('team_roles_role_active_idx');
        });

        Schema::table('permission_role', function (Blueprint $table) {
            $table->dropIndex('permission_role_type_idx');
        });

        Schema::table('permission_team_role', function (Blueprint $table) {
            $table->dropIndex('permission_team_role_type_idx');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex('permissions_section_active_idx');
            if ($this->hasUniqueIndex('permissions', 'permission_key')) {
                $table->dropUnique('permissions_key_unique');
            }
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex('teams_hierarchy_active_idx');
            $table->dropIndex('teams_name_active_idx');
        });
    }

    private function hasUniqueIndex(string $table, string $column): bool
    {
        $indexes = collect(DB::select("SHOW INDEX FROM {$table}"))
            ->where('Column_name', $column)
            ->where('Non_unique', 0);
            
        return $indexes->isNotEmpty();
    }
};
