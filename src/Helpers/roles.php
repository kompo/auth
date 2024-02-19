<?php 

/* GENERAL CONVENTIONS */
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