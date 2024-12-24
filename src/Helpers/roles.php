<?php 

/* GENERAL CONVENTIONS */

use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\Roles\Role;

if(!function_exists('authUser')) {
	function authUser()
	{
		return auth()->user();
	}
}

if(!function_exists('authId')) {
	function authId()
	{
		return auth()->id();
	}
}

/* ROLES */
if(!function_exists('isTeamOwner')) {
	function isTeamOwner()
	{
		return authUser()?->isTeamOwner();
	}
}

if(!function_exists('isSuperAdmin')) {
	function isSuperAdmin()
	{
		return authUser()?->isSuperAdmin();
	}
}

/* OTHER HELPERS */
if(!function_exists('isImpersonated')) {
	function isImpersonated()
	{
		return authUser()?->isImpersonated();
	}
}


function getRoles()
{
	return \Cache::remember('roles', 180, function () {
		return Role::withCount('teamRoles')->orderByDesc('team_roles_count')->get();
	});
}

const PERMISSION_SEPARATOR = ':';

/**
 * Parse the permission key by combining the permission type and the permission key.
 *
 * @param string $permissionKey The permission key.
 * @param PermissionTypeEnum $permissionType The type of the permission. (READ, WRITE, DELETE, ALL)
 * @return string The combined permission key.
 */
function parsePermissionKey($permissionKey, PermissionTypeEnum $permissionType)
{
    return $permissionType->value . PERMISSION_SEPARATOR . $permissionKey;
}

/**
 * Extract the permission key from the combined permission key.
 *
 * @param string $permissionKey The combined permission key.
 * @return string The extracted permission key.
 */
function getPermissionKey($permissionKey)
{
    return substr($permissionKey, 2);
}

/**
 * Extract if the permission is READ, WRITE, DELETE or ALL from the combined permission key.
 *
 * @param string $permissionKey The combined permission key.
 * @return PermissionTypeEnum The extracted permission type (READ, WRITE, DELETE, ALL).
 */
function getPermissionType($permissionKey)
{
    return PermissionTypeEnum::from((int) substr($permissionKey, 0, 1));
}

/**
 * Construct the SQL for creating a complex permission key in a query.
 *
 * @param string $pivotTable The name of the pivot table.
 * @return string The SQL string to construct the complex permission key (type + key).
 */
function constructComplexPermissionKeySql($pivotTable)
{
    return "CONCAT($pivotTable.permission_type, '" . PERMISSION_SEPARATOR . "', permission_key) as complex_permission_key";
}