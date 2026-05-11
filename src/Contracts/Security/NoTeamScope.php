<?php

namespace Kompo\Auth\Contracts\Security;

/**
 * Marker — this model explicitly opts out of team scoping. Read security is
 * still permission-gated, but no `WHERE team_id IN (...)` is applied.
 *
 * Use when a model genuinely has no team relationship (e.g. global lookup
 * tables) AND you don't want the auto-detect / warning fallback to fire.
 * Suppresses the "team_id auto-detected, declare ScopedToTeam" warning.
 */
interface NoTeamScope
{
}
