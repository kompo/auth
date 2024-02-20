<?php

namespace Kompo\Auth\Admin;

use App\Models\User;
use Kompo\Form;

class ImpersonateForm extends Form
{
	protected $lastTeamId;

	public function render()
	{
		if (isImpersonated()) {
		 	return _Link('menu.leave-impersonation')->href('impersonate.leave');
		}

		if (!isSuperAdmin()) {
			return;
		}

		return _Select()->name('impersonate')->placeholder('admin.menu-impersonation')
        	->class('px-3 text-gray-900 select-with-link-options')
        	->searchOptions(0, 'searchUsers', 'retrieveUsers');
	}

	public function searchUsers($search)
	{
		return User::where('id', '<>', authId())->hasNameLike($search)
			->with('currentTeamRole.team')->orderBy('name')->get()
			->mapWithKeys(fn($user) => [
				$user->id => $this->getUserOptionLink($user)
			]);
	}

	protected function getUserOptionLink($user)
	{
		$label = $user->name;

		if ($user->current_team_id != $this->lastTeamId) {
			$label = '<div class="font-semibold">'.$user->currentTeamRole->getTeamName().'</div>'.$label;
			$this->lastTeamId = $user->current_team_id;
		}

		return _Link($label)->class('w-full block text-sm')->href('impersonate', ['id' => $user->id]);
	}
}
