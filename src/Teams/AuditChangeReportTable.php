<?php

namespace Kompo\Auth\Teams;

use Condoedge\Utils\Kompo\Common\Table;
use Kompo\Auth\Models\Teams\TeamChange;

class AuditChangeReportTable extends Table
{
    public function query()
    {
        return TeamChange::where('team_id', currentTeamId())->latest();
    }

    public function top()
    {
        return _FlexBetween(
            _Html('auth-audit-change-report')->class('mb-4 text-xl'),
        );
    }

    public function headers()
    {
        return [
            _Th('auth-date'),
            _Th('auth-user'),
            _Th('auth-change'),
        ];
    }

    public function render($change)
    {
        return _TableRow(

            _Html($change->created_at->translatedFormat('d M Y H:i')),

            _Html($change->user?->name ?: __('auth-deleted')),

            _Html($change->message),

        );
    }
}
