<?php

namespace Kompo\Auth\Teams;

use Kompo\Auth\Models\Teams\TeamChange;

class TeamInfoForm extends TeamBaseForm
{
    protected $_Title = 'crm.team-name';
    protected $_Description = 'crm.team-name-desc';

    public function completed()
    {
        $changes = $this->model->getChanges();

        if(count($changes)) {
            $changesLabel = [
                'nane' => 'translate.name',
            ];

            $changesMessage = collect($changes)->map(fn($val, $name) => __($changesLabel[$name]))->implode('<br>');

            TeamChange::addWithMessage(__('translate.with-values.the-following-changes-were-made-to-your-organization', [
                'fields' => $changesMessage,
            ]));
        }
    }

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
