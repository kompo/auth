<?php

/* Transformers */
function tinyintToBool($value): string
{
    return $value == 1 ? 'Yes' : 'No';
}

function toRounded($value, $decimals = 2): string
{
    return round($value, $decimals);
}


/** Current Team, Roles, etc */
function currentTeam() {
	if (!auth()->user()) {
		return;
	}

	return \Cache::remember('currentTeam'.auth()->id(), 120,
    	fn() => auth()->user()->currentTeam
	);
}

function refreshCurrentTeam()
{
	\Cache::put('currentTeam'.auth()->id(), auth()->user()->currentTeam, 120);
}

function currentTeamId() {
    return 1;
	return auth()->user()?->current_team_id;
}
