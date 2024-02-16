<?php

namespace Kompo\Auth\Auth;

use Kompo\Auth\Common\ImgFormLayout;

class CheckToVerifyEmailForm extends ImgFormLayout
{
	public function rightColumnBody()
	{
		return _Rows(
            _Html('We sent an email to this address. Please go to your inbox and continue the process from there'),
        );
	}
}
