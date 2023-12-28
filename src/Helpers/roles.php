<?php 

/* GENERAL CONVENTIONS */
function authUser()
{
	return auth()->user();
}

function authId()
{
	return auth()->id();
}

/* ROLES */
function isTeamOwner()
{
	return authUser()?->isTeamOwner();
}

function isSuperAdmin()
{
	return authUser()?->isSuperAdmin();
}

/* OTHER HELPERS */
function isImpersonated()
{
    return authUser()?->isImpersonated();
}