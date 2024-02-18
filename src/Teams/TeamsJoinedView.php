<?php

namespace Kompo\Auth\Teams;

class TeamsJoinedView extends TeamBaseForm
{
    protected $_Title = 'Teams I am a part of';
    protected $_Description = 'All of the teams you have joined and your roles in each.';

    protected function body()
    {
        return [
            new TeamsJoinedList()
        ];
    }
}