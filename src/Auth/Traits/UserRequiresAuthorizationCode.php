<?php

namespace Kompo\Auth\Auth\Traits;

use Kompo\Auth\Services\AuthorizationCodeService;

trait UserRequiresAuthorizationCode
{
    protected $allowMultipleVias = false;

    protected function getAuthorizationEmail()
    {
        return request('email'); // Default but it will probably overridden
    }

    protected function getAuthorizationPhone()
    {
        return request('phone'); // Default but it will probably overridden
    }

    protected function getAuthorizationUser()
    {
        return auth()->user();
    }

    protected function getFilledService()
    {
        $service = app(AuthorizationCodeService::class)
            ->setUser($this->getAuthorizationUser())
            ->setEmail($this->getAuthorizationEmail())
            ->setPhone($this->getAuthorizationPhone());

        return $service;
    }

    protected function verifyAuthorizationCode()
    {
        $code = request('authorization_code');
        $type = 'generic';

        return $this->getFilledService()->verify($type, $code);
    }

    public function sendCode()
    {
        $service = $this->getFilledService();
        $availableVias = $service->getAvailableVias();
        $preferredVia = request('via') ?? config('kompo-auth.default_authorization_via')->value;

        $via = collect($availableVias)->firstWhere('value', $preferredVia) ?? $availableVias[0];

        if (! $via) {
            abort(422, __('error-no-valid-contact-method'));
        }

        $service->sendCode(via: $via);

        // I don't like this that much, probably i'll find a better way later
        return $this->codeSentResponse($via->hiddenDestination($this->getAuthorizationUser() ?? (object)[
            'email' => $this->getAuthorizationEmail(),
            'phone' => $this->getAuthorizationPhone(),
        ]));
    }

    protected function codeSentResponse($destination)
    {
        return _Html(__('auth-with-values-code-sent-to', ['destination' => $destination]))->class('text-white opacity-50');
    }

    protected function authorizationElement()
    {
        return _Rows(
            _Card(
                _Html('auth-confirm-your-identity')->class('text-level2 confirm-identity-text mb-2'),
                _Rows(
                    _Rows(
                        $this->sendAuthorizationCodeButtons(),
                    ),
                    _Panel()->id('authorization-code-panel'),
                    _Input()->placeholder('auth-enter-code')->name('authorization_code', false)->class('w-full darkgreen-input text-white !mb-0'),
                )->class('gap-2'),
            )->class(property_exists($this, 'authorizationCodeContainerClass') ? $this->authorizationCodeContainerClass : 'px-6 py-4 bg-greendark border-none'),
        );
    }

    public function sendAuthorizationCodeButtons($user = null)
    {
        $service = $this->getFilledService();
        $defaultVia = config('kompo-auth.default_authorization_via');
        $availableVias = $service->getAvailableVias();

        if ($this->allowMultipleVias && count($availableVias) > 1) {
            return [
                _Button2Outlined('auth-send-code')->selfPost('sendCode')->inPanel('authorization-code-panel')->class('authorization-send-btn'),
                _ButtonGroupSisc2('auth-by')->name('via')->options(
                    collect($availableVias)->mapWithKeys(fn ($case) => [$case->value => $case->label()]),
                )->default($defaultVia->value),
            ];
        }

        $via = $availableVias[0] ?? $defaultVia;

        return _Button2Outlined(__('auth-send-code-type', ['type' => $via->label()]))->selfPost('sendCode')->inPanel('authorization-code-panel')->class('authorization-send-btn');
    }
}
