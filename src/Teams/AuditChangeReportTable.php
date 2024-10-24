<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Common\Table;
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
            _Html('translate.audit-change-report')->class('mb-4 text-xl'),
        );
    }

    public function headers()
    {
        return [
            _Th('translate.date'),
            _Th('translate.user'),
            _Th('translate.change'),
        ];
    }

    public function render($change)
    {
        return _TableRow(

            _Html($change->created_at->translatedFormat('d M Y H:i')),

            _Html($change->user?->name ?: __('translate.deleted')),

            _Html($change->message),

        );
    }
}
