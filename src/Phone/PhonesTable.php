<?php

namespace Kompo\Auth\Phone;

use Kompo\Auth\Models\Phone\Phone;
use Kompo\Table;

class PhonesTable extends Table
{
    protected $teamId;
    protected $phonableId;
    protected $phonableType;

    protected $phonable;

    public function created()
    {
        $this->teamId = currentTeamId();
        $this->phonableId = $this->prop('phonable_id');
        $this->phonableType = $this->prop('phonable_type');

        $this->phonable = findOrFailMorphModel($this->phonableId, $this->phonableType);
    }

	public function query()
	{
		return Phone::where('team_id', $this->teamId)
			->where('phonable_type', $this->phonableType)
			->where('phonable_id', $this->phonableId);
	}

	public function top()
	{
		return _FlexBetween(
            _TitleCard('crm-phones'),
            _CreateCard()->selfCreate('getPhoneForm')->inModal(),
        );
	}

	public function headers()
	{
		return [
			_Th('crm-phone-type'),
			_Th('crm-phone-phone'),
			_Th('crm-phone-is-main'),
			_Th(),
		];
	}

	public function render($phone)
	{
		return _TableRow(
			_Html($phone->type_ph_label),
			_Html($phone->number_ph),
			_HtmlYesNo($this->phonable->primary_phone_id === $phone->id),
        	_Delete($phone),
       )->selfUpdate('getPhoneForm', ['id' => $phone->id])->inModal();
	}

    public function getPhoneForm($id = null)
    {
        return new PhoneForm($id, [
        	'phonable_id' => $this->phonableId,
        	'phonable_type' => $this->phonableType,
        ]);
    }
}
