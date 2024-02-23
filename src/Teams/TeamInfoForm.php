<?php

namespace Kompo\Auth\Teams;

class TeamInfoForm extends TeamBaseForm
{
    protected $_Title = 'Team Name';
    protected $_Description = 'The team\'s name and owner information.';

    protected function body()
    {
        $teamOwner = currentTeam()->owner;

        return [
            _Html('Team Owner')->class('vlFormLabel'),
            _Flex4(
                $teamOwner->getProfilePhotoPill(),
                _Rows(
                    _Html($teamOwner->name),
                    _Html($teamOwner->email)->class('text-gray-700 text-sm'),
                )
            ),
            _Input('Team Name')->name('team_name')->class('mt-4'),
            _FlexEnd(
                _SubmitButton()
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