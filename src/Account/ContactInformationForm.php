<?php

namespace Kompo\Auth\Account;

use Kompo\Form;

class ContactInformationForm extends Form
{
    public $class = 'max-w-xl w-full mx-auto';

    public function created()
    {
        $this->model(auth()->user());
    }

	public function render()
	{
		return [
			_Columns(
                _Input('crm.contact.phone-number')->icon('phone')->name('phone'),
                _Input('crm.contact.website')->icon('<span class="text-xs">https://</span>')->rIcon('globe')->name('website'),
            ),

            _Textarea('crm.contact.address')->name('address')->icon('location-marker'),

            _Html('crm.contact.social-presence')->class('text-gray-300 font-medium mb-2'),

            _Input('Facebook')->icon('fab fa-facebook')->name('facebook'),
            _Input('Linkedin')->icon('fab fa-linkedin')->name('linkedin'),
            _Input('Twitter')->icon('fab fa-twitter')->name('twitter'),

			_FlexEnd(
                _SubmitButton('general.save')
            )
		];
	}

    public function rules()
    {
        return [
            'phone' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
