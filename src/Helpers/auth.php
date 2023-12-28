<?php

function _LinkAlreadyHaveAccount()
{
	return _Link('auth.i-already-have-an-account-log-in-instead')->class('text-sm text-gray-600 self-center')->href('login');
}
