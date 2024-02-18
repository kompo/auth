<?php

namespace Kompo\Auth\Auth;

use Kompo\Auth\Common\ImgFormLayout;
use Kompo\Auth\Models\Teams\EmailRequest;

class CheckToVerifyEmailForm extends ImgFormLayout
{
    protected $imgUrl = 'images/verify-email-image.png';

    public $model = EmailRequest::class;

	public function rightColumnBody()
	{
		return _Panel1(
			$this->getEmailSentConfirmationEls(),
            _Button('Resend invitation link')->selfPost('resendVerificationEmail')->inPanel1(),
        );
	}

	protected function getEmailSentConfirmationEls()
	{
		return _Rows(
            _Html('We sent an email to this address. Please go to your inbox and continue the process from there'),
            _Html('The link is valid for 20 minutes'),
		);
	}

	public function resendVerificationEmail()
    {
        if ($user = $this->model->getRelatedUser()) {

            return _Rows(
            	_Html('There is already an account for this email. Please login'),
            	_Link('Login')->href('login', ['email' => $this->model->email]),
            );

        } else {

            if (!$this->model->hasVerifiedEmail()) {

                $this->model->sendEmailVerificationNotification();

                return $this->getEmailSentConfirmationEls();

            } else {

                return _Rows(
	            	_Html('You have already confirmed your email. Please register here'),
	            	_Link('Register')->href($this->model->getRegisterRoute()),
	            );
            }
        }
    }
}
