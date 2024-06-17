<?php

namespace Kompo\Auth\Teams;

class TeamInfoForm extends TeamBaseForm
{
    protected $_Title = 'crm.team-name';
    protected $_Description = 'crm.team-name-desc';

    protected function body()
    {
        $teamOwner = currentTeam()->owner;

        return [
            _Html('crm.team-owner')->class('vlFormLabel'),
            _Flex4(
                $teamOwner->getProfilePhotoPill(),
                _Rows(
                    _Html($teamOwner->name),
                    _Html($teamOwner->email)->class('text-gray-700 text-sm'),
                )
            ),
            _Input('crm.team-name')->name('team_name')->class('mt-4'),
            _FlexEnd(
                _SubmitButton('general.save')
            )
        ];
    }

    public function rules()
    {
        return [
            'name' => 'required',
        ];
    }
}
