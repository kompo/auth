<?php

/* Transformers */
if(!function_exists('tinyintToBool')) {
	function tinyintToBool($value): string
	{
		return $value == 1 ? 'Yes' : 'No';
	}
}

if(!function_exists('toRounded')) {
	function toRounded($value, $decimals = 2): string
	{
		return round($value, $decimals);
	}
}


/** Current Team, Roles, etc */
if(!function_exists('currentTeam')) {
	function currentTeam() {
		if (!auth()->user()) {
			return;
		}

		return \Cache::remember('currentTeam'.auth()->id(), 120,
			fn() => auth()->user()->currentTeam
		);
	}
}

if(!function_exists('refreshCurrentTeam')) {
	function refreshCurrentTeam()
	{
		\Cache::put('currentTeam'.auth()->id(), auth()->user()->currentTeam, 120);
	}
}

if(!function_exists('currentTeamId')) {
	function currentTeamId() {
		return auth()->user()?->current_team_id;
	}
}
