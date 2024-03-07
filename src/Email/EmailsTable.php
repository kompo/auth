<?php

namespace Kompo\Auth\Email;

use Kompo\Auth\Models\Email\Email;
use Kompo\Table;

class EmailsTable extends Table
{
    protected $teamId;
    protected $emailableId;
    protected $emailableType;

    protected $emailable;

    public function created()
    {
        $this->teamId = currentTeamId();
        $this->emailableId = $this->prop('emailable_id');
        $this->emailableType = $this->prop('emailable_type');

        $this->emailable = findOrFailMorphModel($this->emailableId, $this->emailableType);
    }

	public function query()
	{
		return Email::where('team_id', $this->teamId)
			->where('emailable_type', $this->emailableType)
			->where('emailable_id', $this->emailableId);
	}

	public function top()
	{
		return _FlexBetween(
            _TitleCard('ka::email.emails'),
            _CreateCard()->selfCreate('getEmailForm')->inModal(),
        )->class('mb-4');
	}

	public function headers()
	{
		return [
			_Th('ka::email.email-type'),
			_Th('ka::email'),
			_Th('ka::email.is-main'),
			_Th(),
		];
	}

	public function render($email)
	{
		return _TableRow(
			_Html($email->type_em_label),
			_Html($email->address_em),
			_HtmlYesNo($this->emailable->primary_email_id === $email->id),
        	_Delete($email),
       )->selfUpdate('getEmailForm', ['id' => $email->id])->inModal();
	}

    public function getEmailForm($id = null)
    {
        return new EmailForm($id, [
        	'emailable_id' => $this->emailableId,
        	'emailable_type' => $this->emailableType,
        ]);
    }
}
