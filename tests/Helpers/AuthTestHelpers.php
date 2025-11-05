<?php

namespace Kompo\Auth\Tests\Helpers;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Facades\RoleModel;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Models\Plugins\HasSecurity;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionRole;
use Kompo\Auth\Models\Teams\PermissionSection;
use Kompo\Auth\Models\Teams\PermissionTeamRole;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Models\Teams\TeamRole;
use Str;

/**
 * Auth Test Helpers
 * 
 * Provides reusable methods for creating authorization test scenarios.
 */
class AuthTestHelpers
{
    /**
     * Create a permission with a specific key
     */
    public static function createPermission(string $key, array $attributes = []): Permission
    {
        $section = PermissionSection::firstOrCreate(
            ['name' => 'Test Section']
        );

        if (Permission::where('permission_key', $key)->withTrashed()->exists()) {
            return Permission::where('permission_key', $key)->withTrashed()->first();
        }

        return Permission::firstOrCreate(
            ['permission_key' => $key],
            array_merge([
                'permission_name' => "Permission: {$key}",
                'permission_description' => "Description for {$key}",
                'permission_section_id' => $section->id,
            ], $attributes)
        );
    }

    /**
     * Create a role with specific permissions
     */
    public static function createRole(string $name, array $permissions = [], array $attributes = [])
    {
        $roleId = $attributes['id'] ?? Str::slug($name) . '-' . Str::random(4);
        
        // Create role using manual property assignment (no fillable needed in production code)
        $role = RoleModel::newModelInstance();
        $role->id = $roleId;
        $role->name = $attributes['name'] ?? $name;
        $role->description = $attributes['description'] ?? "Role: {$name}";
        $role->profile = $attributes['profile'] ?? 1;
        $role->from_system = $attributes['from_system'] ?? false;
        
        // Apply any additional attributes
        foreach ($attributes as $key => $value) {
            if (!in_array($key, ['id', 'name', 'description', 'profile', 'from_system'])) {
                $role->$key = $value;
            }
        }
        
        $role->save();

        foreach ($permissions as $permissionKey => $type) {
            $permission = static::createPermission($permissionKey);
            
            // Create permission role using manual property assignment (no fillable needed)
            $permissionRole = PermissionRole::where('permission_id', $permission->id)
                ->where('role', $role->id)
                ->first();
                
            if (!$permissionRole) {
                $permissionRole = new PermissionRole();
                $permissionRole->permission_id = $permission->id;
                $permissionRole->role = $role->id;
                $permissionRole->permission_type = $type;
                $permissionRole->save();
            }
        }

        return $role;
    }

    /**
     * Create a team with optional owner
     */
    public static function createTeam(array $attributes = [], $owner = null)
    {
        if (!$owner) {
            $owner = UserFactory::new()->create();
        }

        // Create team using manual property assignment (no fillable needed)
        $team = TeamModel::newModelInstance();
        $team->team_name = $attributes['team_name'] ?? ('Test Team ' . uniqid());
        $team->user_id = $attributes['user_id'] ?? $owner->id;
        
        if (isset($attributes['parent_team_id'])) {
            $team->parent_team_id = $attributes['parent_team_id'];
        }
        
        // Apply any additional attributes
        foreach ($attributes as $key => $value) {
            if (!in_array($key, ['team_name', 'user_id', 'parent_team_id'])) {
                $team->$key = $value;
            }
        }
        
        $team->save();
        
        return $team;
    }

    /**
     * Create a team hierarchy (parent with children)
     */
    public static function createTeamHierarchy(int $depth = 2, $owner = null): array
    {
        if (!$owner) {
            $owner = UserFactory::new()->create();
        }

        $teams = [];
        
        // Create root team
        $rootTeam = static::createTeam(['team_name' => 'Root Team'], $owner);
        $teams['root'] = $rootTeam;
        
        if ($depth >= 2) {
            // Create child teams
            $childA = static::createTeam([
                'team_name' => 'Child Team A',
                'parent_team_id' => $rootTeam->id,
            ], $owner);
            
            $childB = static::createTeam([
                'team_name' => 'Child Team B',
                'parent_team_id' => $rootTeam->id,
            ], $owner);
            
            $teams['childA'] = $childA;
            $teams['childB'] = $childB;
        }
        
        if ($depth >= 3) {
            // Create grandchild teams
            $teams['grandchildA1'] = static::createTeam([
                'team_name' => 'Grandchild A1',
                'parent_team_id' => $teams['childA']->id,
            ], $owner);
            
            $teams['grandchildA2'] = static::createTeam([
                'team_name' => 'Grandchild A2',
                'parent_team_id' => $teams['childA']->id,
            ], $owner);
            
            $teams['grandchildB1'] = static::createTeam([
                'team_name' => 'Grandchild B1',
                'parent_team_id' => $teams['childB']->id,
            ], $owner);
        }
        
        return $teams;
    }

    /**
     * Assign a role to a user in a team
     */
    public static function assignRoleToUser(
        $user,
        $role,
        $team,
        RoleHierarchyEnum $hierarchy = RoleHierarchyEnum::DIRECT,
        array $attributes = []
    ): TeamRole {
        // Create team role using manual property assignment (no fillable needed)
        $teamRole = new TeamRole();
        $teamRole->user_id = $user->id;
        $teamRole->team_id = $team->id;
        $teamRole->role = $role->id;
        $teamRole->role_hierarchy = $hierarchy;
        
        // Apply any additional attributes
        foreach ($attributes as $key => $value) {
            if (!in_array($key, ['user_id', 'team_id', 'role', 'role_hierarchy'])) {
                $teamRole->$key = $value;
            }
        }
        
        $teamRole->save();
        
        return $teamRole;
    }

    /**
     * Create a user with role and permissions in a team
     */
    public static function createUserWithRole(
        array $permissions,
        $team = null,
        RoleHierarchyEnum $hierarchy = RoleHierarchyEnum::DIRECT,
        string $roleName = 'Test Role'
    ) {
        /**
         * @var \Illuminate\Auth\Authenticatable $user
         */
        $user = UserFactory::new()->create();
        $team = $team ?? static::createTeam([], $user);
        $role = static::createRole($roleName, $permissions);
        
        $teamRole = static::assignRoleToUser($user, $role, $team, $hierarchy);
        
        // Set as current team role
        $user->current_team_role_id = $teamRole->id;
        $user->save();
        
        return [
            'user' => $user,
            'role' => $role,
            'team' => $team,
            'teamRole' => $teamRole,
        ];
    }

    /**
     * Create a user with multiple roles in different teams
     */
    public static function createUserWithMultipleRoles(array $rolesConfig): array
    {
        $user = UserFactory::new()->create();
        $result = ['user' => $user, 'roles' => [], 'teams' => [], 'teamRoles' => []];
        
        foreach ($rolesConfig as $config) {
            $team = $config['team'] ?? static::createTeam([], $user);
            $role = static::createRole(
                $config['roleName'] ?? 'Role ' . uniqid(),
                $config['permissions'] ?? []
            );
            
            $teamRole = static::assignRoleToUser(
                $user,
                $role,
                $team,
                $config['hierarchy'] ?? RoleHierarchyEnum::DIRECT
            );
            
            $result['roles'][] = $role;
            $result['teams'][] = $team;
            $result['teamRoles'][] = $teamRole;
        }
        
        // Set first team role as current
        if (!empty($result['teamRoles'])) {
            $user->current_team_role_id = $result['teamRoles'][0]->id;
            $user->save();
        }
        
        return $result;
    }

    /**
     * Add a direct permission to a team role (override role permissions)
     */
    public static function addDirectPermissionToTeamRole(
        TeamRole $teamRole,
        string $permissionKey,
        PermissionTypeEnum $type
    ): void {
        HasSecurity::enterBypassContext();
        $permission = static::createPermission($permissionKey);

        $permissionTeamRole = PermissionTeamRole::where('team_role_id', $teamRole->id)
            ->where('permission_id', $permission->id)
            ->first();
        
        if (!$permissionTeamRole) {
            $permissionTeamRole = new PermissionTeamRole();
            $permissionTeamRole->team_role_id = $teamRole->id;
            $permissionTeamRole->permission_id = $permission->id;
        }

        $permissionTeamRole->permission_type = $type;
        $permissionTeamRole->save();

        $permissionTeamRole->refresh();
        HasSecurity::exitBypassContext();
    }

    /**
     * Create a scenario: User with DENY on one role, ALLOW on another
     */
    public static function createDeniedScenario(): array
    {
        $user = UserFactory::new()->create();
        $team = static::createTeam([], $user);
        
        // Role with ALLOW
        $allowRole = static::createRole('Allow Role', [
            'TestResource' => PermissionTypeEnum::ALL,
        ]);
        
        // Role with DENY
        $denyRole = static::createRole('Deny Role', [
            'TestResource' => PermissionTypeEnum::DENY,
        ]);
        
        $allowTeamRole = static::assignRoleToUser($user, $allowRole, $team);
        $denyTeamRole = static::assignRoleToUser($user, $denyRole, $team);
        
        $user->current_team_role_id = $allowTeamRole->id;
        $user->save();
        
        return [
            'user' => $user,
            'team' => $team,
            'allowRole' => $allowRole,
            'denyRole' => $denyRole,
            'allowTeamRole' => $allowTeamRole,
            'denyTeamRole' => $denyTeamRole,
        ];
    }

    /**
     * Create a test model class dynamically
     */
    public static function createTestModel(
        string $tableName,
        string $className,
        array $modelProperties = []
    ): string {
        // This is a simplified version - in real tests, you might use temporary model classes
        // or mock the model behavior
        
        // For now, we'll assume tests use predefined test models
        return $className;
    }

    /**
     * Clear all permission-related data
     */
    public static function clearPermissionData(): void
    {
        PermissionTeamRole::query()->delete();
        PermissionRole::query()->delete();
        TeamRole::query()->delete();
        Permission::query()->delete();
        PermissionSection::query()->delete();
        RoleModel::query()->delete();
    }
}

