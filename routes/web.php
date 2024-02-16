<?php

use Illuminate\Support\Facades\Route;

//AUTH
Route::layout('layouts.guest')->middleware(['guest:web'])->group(function(){

	Route::get('login', Kompo\Auth\Auth\BaseEmailForm::class)->name('login');

	Route::get('login-password/{email?}', Kompo\Auth\Auth\LoginForm::class)->name('login.password');

	Route::get('check-to-verify-email', Kompo\Auth\Auth\CheckToVerifyEmailForm::class)->name('check.verify.email');

	Route::middleware(['signed', 'throttle:6,1'])->group(function(){

		Route::get('register/{email_request_id}', Kompo\Auth\Auth\RegisterForm::class)->name('register');

		Route::get('email/verify/{hash}', [VerifyEmailController::class, '__invoke'])->name('verification.verify');

	});

	Route::get('forgot-password', Kompo\Auth\Auth\ForgotPasswordForm::class)->name('password.request');

	Route::get('reset-password/{token}', Kompo\Auth\Auth\ResetPasswordForm::class)->name('password.reset');
	
});


//TEAMS
Route::layout('layouts.dashboard')->middleware(['auth'])->group(function(){

	Route::get('teams/manage', Kompo\Auth\Teams\TeamManagementPage::class)->name('teams.manage');

});


Route::layout('layouts.guest')->middleware(['guest'])->group(function(){

	Route::get('team-invitations/{invitation}', Kompo\Auth\Teams\TeamInvitationRegisterForm::class)->name('team-invitations.accept')
        ->middleware(['signed']);
        
});

//ACCOUNT
Route::layout('layouts.dashboard')->middleware(['auth'])->group(function(){

	Route::get('user/profile', Kompo\Auth\Account\ProfileInformationForm::class)->name('profile.show');

	Route::get('user/password', Kompo\Auth\Account\UpdatePasswordForm::class)->name('password.update.form');

});


//CORE
Route::layout('layouts.dashboard')->middleware(['auth'])->group(function(){

    Route::get('mail-preview-local', Kompo\Auth\Admin\AdminMailPreviewTable::class)->name('admin.mail-preview');

});