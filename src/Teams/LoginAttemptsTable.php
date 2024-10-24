<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Common\Table;
use Kompo\Auth\Models\Teams\Team;
use Kompo\Auth\Models\LoginAttempt;

class LoginAttemptsTable extends Table {
    protected $team;

    public function created()
    {
        $teamId = $this->prop('team_id');

        if($teamId) $this->team = Team::findOrFail($teamId);
    }

    public function query()
    {
        return LoginAttempt::query()
            ->when($this->team, fn($q) => $q->whereIn('email', $this->team->users->pluck('email')))
            ->when(request('success') != null, fn($q) => $q->where('success', request('success')))
            ->latest();
    }

    public function top()
    {
        return _FlexBetween(
            _Html('translate.login-attempts')->class('mb-4 text-xl'),
            _Select()->options([
                1 => __('translate.success'),
                0 => __('translate.failed'),
            ])->name('success', false)->placeholder('translate.login-attempt-filter')->filter()->class('w-60 whiteField')
        );
    }

    public function headers()
    {
        return [
            _Th('translate.date')->sort('created_at'),
            _Th('translate.email')->sort('email'),
            _Th('translate.ip-address'),
            _Th('translate.status'),
            _Th('translate.type'),
        ];
    }

    public function render($loginAttempt)
    {
        return _TableRow(

            _Html($loginAttempt->created_at->translatedFormat('d M Y H:i')),

            _Html($loginAttempt->email),

            _Html($loginAttempt->ip),

            $loginAttempt->getStatusPill(),

            _Html($loginAttempt->type_label),

        );
    }
}
