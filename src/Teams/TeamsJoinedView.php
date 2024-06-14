<?php

namespace Kompo\Auth\Teams;

class TeamsJoinedView extends TeamBaseForm
{
    protected $_Title = 'crm.teams-i-am-part-of';
    protected $_Description = 'crm.teams-i-am-part-of-desc';

    protected function body()
    {
        return [
            new TeamsJoinedList()
        ];
    }
}
