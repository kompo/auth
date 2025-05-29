# Kompo Auth Package

## Overview

Kompo Auth is a comprehensive authorization and authentication package designed to provide a complete role and permission management system for Laravel applications. The package abstracts complex security logic into simple database configurations, requiring minimal code changes to your models and components.

## Key Features

- **Role-based access control (RBAC)** across your entire application
- **Team-based permissions** with hierarchy support
- **Multiple permission types** (READ, WRITE, ALL, DENY)
- **Automatic security checks** on models and components
- **Permission caching** for optimal performance
- **Sensitive field protection** to hide specific model attributes

## Installation

### 1. Install the package using Composer

```bash
composer require kompo/auth
```

### 2. Run migrations

```bash
php artisan migrate
```

### 3. Publish configuration files

```bash
php artisan vendor:publish --provider="Kompo\Auth\KompoAuthServiceProvider"
```

### 4. Add styles (optional)

```scss
// In resources/scss/app.scss
@import "kompo/auth";
```

## Automatic Security System (Fast usage)

(You can just use the next steps into the db to get a fast authorizated app. For more details you can see also the next parts and get more about more specific usage, bypass and structure)

Kompo Auth uses naming conventions and model properties to automatically enforce security across your application:

### Permission Keys Naming Conventions

The system automatically restricts access based on these naming patterns:

1. **Model Class Names**: Each model is automatically protected using its class name as the permission key

*Example*
`User` model is protected by the `User` permission key
`Project` model is protected by the `Project` permission key

2. **Component Class Names**: Components are protected using their class name as the permission key

*Example*
`AssignRoleModal` component is protected by the `AssignRoleModal` permission key

3. **Sensitive Field Protection**: Add `.sensibleColumns` suffix to model name to control field visibility. (You should've set $sensibleColumns property into the model to enable this).

*Example*
`User.sensibleColumns` permission controls access to sensitive user fields.

### Seed-Based Permission Configuration

The recommended workflow is:

1. **Seed All Permission Records**: Create database records for all models and components
   ```php
   // In your database seeder
   Permission::create([
       'permission_key' => 'User',
       'permission_name' => 'User Management',
       'permission_section_id' => $adminSection->id,
   ]);
   
   Permission::create([
       'permission_key' => 'User.sensibleColumns',
       'permission_name' => 'Access to sensitive user fields',
       'permission_section_id' => $adminSection->id,
   ]);
   ```

2. **Grant Permissions to Roles**: Assign appropriate permission types to each role
   ```php
   $adminRole->permissions()->attach($userPermission->id, ['permission_type' => PermissionTypeEnum::ALL]);
   $editorRole->permissions()->attach($userPermission->id, ['permission_type' => PermissionTypeEnum::WRITE]);
   $viewerRole->permissions()->attach($userPermission->id, ['permission_type' => PermissionTypeEnum::READ]);
   ```

3. **Result**: Security is enforced automatically throughout your application with minimal code

### Visual Components for Role Management

Kompo Auth includes powerful visual components for managing roles and permissions:

1. **Roles and Permissions Matrix**: A comprehensive interface for setting permissions
   ```php
   // Add to your admin panel
   new RolesAndPermissionMatrix()
   ```

2. **Role Assignment Modal**: For assigning roles to users within teams
   ```php
   _Button('Assign Role')->selfGet('getAssignRoleModal')->inModal()
   
   public function getAssignRoleModal()
   {
       return new AssignRoleModal([
           'user_id' => $userId,
           'team_id' => $teamId, // Optional
       ]);
   }
   ```

3. **Team Member Management**: Components for inviting and managing team members with specific roles
   ```php
   new TeamMembersList(['team_id' => $teamId])
   ```

The visual interface allows administrators to:
- Assign fine-grained READ, WRITE, ALL, or DENY permissions
- Group permissions by sections for better organization
- Apply permissions to entire sections with a single click
- Set role hierarchies with inheritance options

4. **Dynamic role selector**: A team role switcher when you can impersonate different team_roles
```php
new OptionsRolesSwitcher()
```

## Authorization System Design

### Database Structure

The package uses the following tables to manage roles and permissions:

- `users`: Standard user information
- `teams`: Team information with owner reference
- `roles`: Role definitions with profile settings
- `permission_sections`: Organizational grouping of permissions
- `permissions`: Individual permission definitions with keys
- `permission_role`: Associates permissions with roles
- `team_roles`: Associates users with teams and assigns roles
- `permission_team_role`: Direct permission overrides for user-team combinations

### Database Diagram

[View or edit diagram on dbdiagram.io](https://dbdiagram.io/d/6703f4b8fb079c7ebd9db055)

### Team Hierarchy & Role Inheritance

Kompo Auth provides a sophisticated team hierarchy system with dynamic role creation based on inheritance settings:

#### Teams Organization

Teams can be organized in parent-child relationships, creating a tree structure:

```
Root Team
├── Child Team 1
│   ├── Grandchild Team 1
│   └── Grandchild Team 2
└── Child Team 2
    └── Grandchild Team 3
```

- **Root Team**: The top-level team, usually representing an organization or a major division.
- **Child Teams**: Teams that belongs to a parent team, including grandchild teams.
- **Neighboring Teams**: Teams that are at the same level in the hierarchy.

#### Teams Hierarchy

Team roles can accept inheritance so the user that has them will have the permissions on all the children teams or neighbouring teams.

The role_hierarchy column on team_roles depends on the `RoleHierarchyEnum` that defines how permissions cascade through team hierarchies:

**DIRECT**: Access limited to only the specific team
**DOWN** Access extends to the team and all its children
**SIBLINGS**: Access extends to the team and its sibling teams
**DOWN_AND_SIBLINGS**: Access extends to the team, its children, and siblings

The roles must accept those configurations on the accept_roll_to_children and accept_roll_to_neighbour fields.

#### Lazy Role Creation

The basic team roles are created when you assign them to an user. But in the roles switcher you can see all the inherited teams and they will be created dynamically so you can enter to their dashboard.

When you try to set an unexistent role as your current role and you have a team role that allows inheritance it'll be created it in that moment using `TeamRole::getParentHierarchyRole()` inside of `TeamRole::getOrCreateForUser()`

## Permission Types

Kompo Auth uses a bitmask system for permission types:

- `READ (1)`: View-only access
- `WRITE (3)`: Read and write access (includes READ)
- `ALL (7)`: Complete access (includes READ and WRITE)
- `DENY (100)`: Explicitly denies access, overriding other permissions

## Security Implementation

### Model-Level Security

Kompo Auth automatically protects your models by adding global scopes and event listeners. To enable security on a model:

1. Ensure your model extends `Condoedge\Utils\Models\Model` (includes the HasSecurity plugin)
2. Add permission records in the database that match your model name
3. Configure security restrictions through model properties (optional):

```php
// Control security behavior with these properties
protected $readSecurityRestrictions = true;
protected $saveSecurityRestrictions = true; 
protected $deleteSecurityRestrictions = true;
protected $restrictByTeam = true;

// Define sensitive fields that require special permission
protected $sensibleColumns = ['secret_field', 'confidential_data'];
```

4. For team-based restrictions, you can use the `scopeSecurityForTeams` method for mass record restrictions or the `getTeamOwnersIds` method for individual record restrictions:

#### Mass Record Restrictions

The `scopeSecurityForTeams` method allows you to apply custom logic for restricting records by team. For example:

```php
public function scopeSecurityForTeams($query, $teamIds)
{
    $query->whereIn('team_id', $teamIds);
}
```

#### Individual Record Restrictions

The `getTeamOwnersIds` method determines the team ownership of an individual record. Ensure your model implements the `securityRelatedTeamIds` method or has a `team_id` column:

```php
// Method to define security-related team IDs
public function securityRelatedTeamIds()
{
    return $this->teams->pluck('id')->toArray();
}

// Alternatively, ensure the team_id column exists
Schema::table('your_table', function (Blueprint $table) {
    $table->unsignedBigInteger('team_id');
});
```

If neither is implemented, the record will not be restricted by team. This logic complements the `scopeSecurityForTeams` method for broader team-based restrictions.

### Bypass Security When Needed

There are several ways to bypass security checks when necessary:

```php
// Use system methods for privileged operations
$model->systemSave();
$model->systemDelete();

// Set bypass flag before operation
$model->_bypassSecurity = true;
$model->save();

// Remove global scopes for a specific query
Model::withoutGlobalScope('authUserHasPermissions')->get();
```

### Automatic User Access to Own Records

The security system ensures users always have access to their own records, regardless of their role-based permissions. This prevents users from being locked out of their own data.

**How it works:**

1. **User ID based automatic bypass:**

   ```php
   // When a model has a user_id column matching the authenticated user,
   // security restrictions are automatically bypassed
   // This is built into HasSecurity plugin and requires no additional code
   ```

2. **Define user ownership scope:**

   ```php
   // For more complex ownership relationships, define this scope in your model:
   public function scopeUserOwnedRecords($query)
   {
       // Define your logic for identifying records owned by current user
       // Examples:
       return $query->where('user_id', auth()->id());
       // Or for more complex relationships:
       return $query->where('creator_id', auth()->id())
                   ->orWhereHas('participants', function($q) {
                       $q->where('user_id', auth()->id());
                   });
   }
   ```

3. **Custom user access method:**

   ```php
   // For even more complex scenarios, you can define:
   public function usersIdsAllowedToManage()
   {
       // Return array of user IDs that should have access regardless of permissions
       return [$this->user_id, $this->manager_id, $this->company->owner_id];
   }
   ```

This ownership system ensures that:

- Users always see their own records in queries
- Users can edit their own records even without explicit permissions
- Custom ownership relationships can be easily defined
- Security remains tight for non-owned records

**Implementation Notes:**

- The bypasses only apply to the authenticated user's own records
- This works automatically with the global scope applied to queries
- During save/delete operations, the system checks for ownership before enforcing permissions
- Always consider adding a `bypassToAuthenticatedUser` scope to your models for clarity

### Component-Level Security

For Kompo components (forms, tables, etc.), security is provided through the `HasAuthorizationUtils` plugin:

#### Using the checkAuth Macro

The `checkAuth` macro allows you to conditionally show or hide UI elements based on user permissions:

```php
// Basic syntax
_Button('Create user')->checkAuth('User');

// Example with nested components
_Rows(
    _Html('Access to people')->checkAuth('Person'),
    _Link('View details')->checkAuth('Project', PermissionTypeEnum::READ),
    _Button('Edit profile')->checkAuth('User', PermissionTypeEnum::WRITE)
);
```

**checkAuth Parameters:**
```php
// checkAuth(resource, permission type, team, message)
_Button('Delete')
    ->checkAuth(
        'Record',                        // Resource to check
        $teamId,                         // Team ID (optional)
        false                            // Retun null instead of a void element
    );
```

If permission is denied, the element:

- Is will be rendered using null data
- Or it will return a fully null

## Implementation Strategies

Kompo Auth offers two main approaches for implementing security based on your project needs:

### 1. "Security First" Approach (Recommended)

Everything is restricted by default and permissions are explicitly granted:

```php
// In your models (default settings)
class Document extends Model 
{
    // No configuration needed - security is enabled by default
}

// In your database
// Create permissions for each resource and assign them to specific roles
```

**Benefits:**
- Maximum security: nothing is accessible without explicit permission
- Granular control over all resources
- Ideal for applications with sensitive data

**Implementation steps:**
1. Create permissions for each model and component
2. Assign these permissions to specific roles
3. Resources without permission will not be accessible

### 2. "Progressive Security" Approach

Start with minimal restrictions and add security as needed:

```php
// In config/kompo-auth.php
'security' => [
    'default-read-security-restrictions' => false,
    'default-save-security-restrictions' => false,
]

// Then activate security only on specific models
class SensitiveDocument extends Model
{
    protected $readSecurityRestrictions = true;
    protected $saveSecurityRestrictions = true;
}
```

**Benefits:**
- Easier gradual implementation
- Works well for migrating existing systems
- Allows protecting only critical operations

**Manual checks:**
```php
// Explicit checks where needed
if (!auth()->user()->hasPermission('Report', PermissionTypeEnum::WRITE)) {
    return redirect()->back()->withErrors('Unauthorized');
}
```

### Practical Example

A typical security implementation flow:

1. **Permission design:**
   - Identify critical resources (users, payments, settings)
   - Define sensitive operations (deleting records, changing roles)

2. **Implementation:**
   ```php
   // In sensitive models
   protected $readSecurityRestrictions = true;
   protected $sensibleColumns = ['confidential_data'];
   
   // In UI for critical elements
   _Button('Delete account')->checkAuth('User', PermissionTypeEnum::ALL);
   ```

3. **User verification:**
   ```php
   // Check if user can view a specific resource
   if ($user->hasPermission('Project', PermissionTypeEnum::READ, $teamId)) {
       // Show resource
   }
   ```

This flexible approach allows you to adjust the security level according to your application's specific needs.

## Common Usage Patterns

### Check If User Has Permission

```php
if (auth()->user()->hasPermission('User', PermissionTypeEnum::READ)) {
    // User can read User records
}

// Check for team-specific permission
if (auth()->user()->hasPermission('Post', PermissionTypeEnum::WRITE, $teamId)) {
    // User can write to Posts in the specific team
}
```

### Know if the team it's inside of the team

This will check if there's a team_roles record linking the team to the user. It allows also hierarchy so if the role it's rolled down to children and the user is into a parent team it'll return true

```php
$teamIds = auth()->user()->hasAccessToTeam($teamId)
```

### Find Teams With Permission

```php
// Get all teams where user can manage Projects
$teamIds = auth()->user()->getTeamsIdsWithPermission('Project', PermissionTypeEnum::WRITE);
```

### Grant Permission To User (NOT Recommended yet)

```php
// Give a user permission directly on their current team role
auth()->user()->givePermissionTo('CreateReports');

// Or specify a team role
auth()->user()->givePermissionTo('ManageUsers', $teamRoleId);
```

## Developer Guide: Package Architecture

This section provides a streamlined view of how the package works internally, its key components, and how to effectively implement and debug permission-related issues.

### Core Components & Flow

```
┌─────────────────────────┐
│ KompoAuthServiceProvider│◄──────────────┐
└───────────┬─────────────┘               │
            │                             │
            ▼                             │
┌───────────────────────┐    ┌───────────────┐
│    HasSecurity        │◄───┤   Models      │
│    (Model Plugin)     │    │               │
└───────────┬───────────┘    └───────────────┘
            │
            │
┌───────────▼───────────┐    ┌───────────────┐
│ HasAuthorizationUtils │◄───┤  UI Elements  │
│ (Component Plugin)    │    │               │
└───────────────────────┘    └───────────────┘

┌───────────────────────┐    ┌───────────────┐
│ HasTeamsTrait         │◄───┤  User Model   │
│                       │    │               │
└───────────────────────┘    └───────────────┘
```

### Key Files & Responsibilities

| File | Responsibility |
|------|----------------|
| `KompoAuthServiceProvider.php` | Bootstrap, registers services, binds model plugins |
| `HasSecurity.php` | Model security: global scopes, event listeners for CRUD operations |
| `HasAuthorizationUtils.php` | UI security: form/query/component permission checks |
| `HasTeamsTrait.php` | User permissions, team management, permission caching |
| `Permission.php` | Permission storage and retrieval |
| `TeamRole.php` | Team-user-role relationships and inheritance |

### Security Enforcement Sequence

```
1. Query Builder Created
   └─> HasSecurity global scope triggered
       └─> Check for global bypass
           └─> No bypass: Apply permission filters
               └─> Check for team context
                   └─> Restrict to authorized teams
                       └─> Check for user ownership
                           └─> Allow access to owned records
```

**Model Read Operation:**
```php
// Check permission existence
Permission::findByKey('User')->exists();

// Test permission with debug mode
auth()->user()->hasPermission('User', PermissionTypeEnum::READ, null, true);

// Check team permissions
auth()->user()->hasAccessToTeam($teamId);
$teamsWithAccess = auth()->user()->getTeamsIdsWithPermission('Resource');

// Cache inspection
\Cache::get('currentPermissions' . auth()->id());
\Cache::tags(['permissions'])->flush(); // Force clear cache
```

## Developer Guide: Authorization Flow

Understanding how the KompoAuth package processes security checks can help you implement permissions correctly and debug access issues. This section explains the key workflows in the authorization system.

### Permission Check Workflow

```
┌─────────────────┐     ┌──────────────────┐     ┌───────────────────┐
│  hasPermission  │────▶│ Get Permissions  │────▶│ Check Permission  │
│    Request      │     │   From Cache     │     │      Match        │
└─────────────────┘     └──────────────────┘     └───────────────────┘
```

1. **Initial Check**: `$user->hasPermission('Resource', PermissionTypeEnum::READ, $teamId)`
   - First checks if security is globally bypassed
   - Retrieves cached permissions for user (either all teams or specified teams)
   
2. **Permission Resolution**:
   - Formats permission key to standard format
   - Checks if any permission in cache matches requested key and type
   - Considers DENY permissions which override other permissions
   
3. **Team Context**:
   - Without team context: checks permissions across all teams
   - With team context: checks only permissions applicable to specified team(s)
   - Considers team hierarchies (parent/child relationships)

### Model Security Flow

1. **Query Filtering**:
   - Global scope automatically filters records based on permissions
   - For team-restricted models, limits to authorized teams
   - Special bypass logic ensures users can always access their own records

2. **Write Operations**:
   - Before save: checks if user has WRITE permission
   - Before delete: checks if user has WRITE permission
   - Owner bypass: automatically allows users to modify their own records

3. **Field Protection**:
   - After retrieval: checks for sensitive fields
   - Removes sensitive fields if user lacks required permission
   - Applies only if model defines sensibleColumns property

### Component Authorization Flow

```
┌─────────────┐     ┌───────────────┐     ┌─────────────────┐
│  Component  │────▶│ checkAuth()   │────▶│ Visible/Hidden  │
│   Render    │     │    Macro      │     │    Element      │
└─────────────┘     └───────────────┘     └─────────────────┘
```

1. **Component Rendering**:
   - During boot: verifies READ permission for component
   - Permission key derived from component class name
   - Hidden if permission check fails

2. **Element Display Control**:
   - `checkAuth()` macro verifies permission for UI elements
   - Conditionally renders elements based on permission result
   - Can include fallback behavior for unauthorized state

3. **Form Submission**:
   - Before processing: verifies WRITE permission
   - Forms automatically inherit permission checks from their class name
   - Provides consistent security between UI and backend

### Bypass Mechanisms

The security system includes several bypass mechanisms that work in this order:

1. **SuperAdmin Email**: Users with emails listed in `config('superadmin-emails')` bypass all security checks
2. **Global Bypass**: `config('kompo-auth.security.bypass-security')` setting
3. **Custom Bypass Method**: `isSecurityBypassRequired()` on model
4. **User ID Match**: Automatic bypass when `user_id` matches authenticated user
5. **Custom Users List**: `usersIdsAllowedToManage()` method on model
6. **Custom Scope**: `scopeUserOwnedRecords()` method on model
7. **Explicit Flag**: `$model->_bypassSecurity = true` attribute
8. **System Methods**: `$model->systemSave()` and `$model->systemDelete()`
9. **Running in console**: The security will be automatically bypassed when the app is running in console

These mechanisms ensure that while security is enforced consistently, there are appropriate methods to bypass it when necessary, particularly for allowing users to access their own records.

## Debugging Permission Issues

When troubleshooting access problems:

1. **Check Cache**: Permission results are cached. Clear cache with `php artisan cache:clear` to ensure fresh checks.

2. **Verify Permissions**: Ensure the permission exists in the database:
   ```php
   // Does the permission exist?
   \Kompo\Auth\Models\Teams\Permission::findByKey('User')
   
   // Does user have access? (Debug mode)
   auth()->user()->hasPermission('User', PermissionTypeEnum::READ, null, true)
   ```

3. **Check Bypass Logic**: For model access, ensure appropriate bypass methods are defined:
   ```php
   // Add this scope to your model
   public function scopeUserOwnedRecords($query)
   {
       // Logic to identify user's own records
       return $query->where('user_id', auth()->id());
   }
   ```

4. **Examine Team Hierarchy**: Team permissions can be affected by parent/child relationships.

Remember that the security system is designed to be restrictive by default - you need to explicitly grant permissions for users to access resources.