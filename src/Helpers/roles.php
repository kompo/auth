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
		return Role::all();
	});
}

const PERMISSION_SEPARATOR = ':';

function parsePermissionKey($permissionKey, PermissionTypeEnum $permissionType)
{
	return $permissionType->value . PERMISSION_SEPARATOR . $permissionKey;
}

function getPermissionKey($permissionKey)
{
	return substr($permissionKey, 2);
}

function getPermissionType($permissionKey)
{
	return PermissionTypeEnum::from((int) substr($permissionKey, 0, 1));
}

function constructComplexPermissionKeySql($pivotTable){
	return "CONCAT($pivotTable.permission_type, '" . PERMISSION_SEPARATOR . "', permission_key) as complex_permission_key";
}