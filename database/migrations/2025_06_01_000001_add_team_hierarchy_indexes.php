<?php

// database/migrations/2025_06_01_000001_add_team_hierarchy_indexes.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('teams', function (Blueprint $table) {
            // Índice compuesto para parent_team_id (crítico para jerarquías)
            $table->index(['parent_team_id', 'deleted_at'], 'teams_parent_hierarchy_idx');
            
            // Índice para búsquedas por nombre en jerarquías
            $table->index(['team_name', 'parent_team_id'], 'teams_name_parent_idx');
        });

        Schema::table('team_roles', function (Blueprint $table) {
            // Índices compuestos para optimizar queries de permisos
            $table->index(['user_id', 'team_id', 'role'], 'team_roles_user_team_role_idx');
            $table->index(['team_id', 'role', 'deleted_at'], 'team_roles_team_role_active_idx');
        });

        Schema::table('permissions', function (Blueprint $table) {
            // Índice único para permission_key (búsquedas frecuentes)
            $table->unique(['permission_key'], 'permissions_key_unique');
        });
    }

    public function down()
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex('teams_parent_hierarchy_idx');
            $table->dropIndex('teams_name_parent_idx');
        });

        Schema::table('team_roles', function (Blueprint $table) {
            $table->dropIndex('team_roles_user_team_role_idx');
            $table->dropIndex('team_roles_team_role_active_idx');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropUnique('permissions_key_unique');
        });
    }
};