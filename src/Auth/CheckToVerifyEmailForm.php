<?php

namespace Kompo\Auth\Auth;

use Condoedge\Utils\Kompo\Common\ImgFormLayout;
use Kompo\Auth\Models\Teams\EmailRequest;

class CheckToVerifyEmailForm extends ImgFormLayout
{
    protected $imgUrl = 'images/verify-email-image.png';

    public $model = EmailRequest::class;

	public function rightColumnBody()
	{
		return _Panel1(
			$this->getEmailSentConfirmationEls(),

            _Button('auth-resend-invitation-link')->selfPost('resendVerificationEmail')->inPanel1()->class('w-full'),
        );
	}

	protected function getEmailSentConfirmationEls()
	{
		return _Rows(
            _TitleModal('auth-email-sent-confirmation-1')->mb4(),
            _TitleModalSub('auth-email-sent-confirmation-2')->class('mb-12'),
		);
	}

	public function resendVerificationEmail()
    {
        if ($user = $this->model->getRelatedUser()) {

            return _Rows(
            	_Html('auth-there-is-already-an-account-for-this-email'),
            	_Link('auth-login')->href('login', ['email' => $this->model->email]),
            );

        } else {

            $this->model->sendEmailVerificationNotification();

            return $this->getEmailSentConfirmationEls();
        }
    }
}
