<?php

use Illuminate\Support\Facades\Route;
use Kompo\Auth\Http\Controllers\NotificationsController;
use Laravel\Socialite\Facades\Socialite;

//PACKAGES
Route::impersonate();

//AUTH
Route::layout('layouts.guest')->middleware(['guest:web'])->group(function(){

	Route::get('login', Kompo\Auth\Auth\BaseEmailForm::class)->name('login');

	Route::get('login-password/{email?}', Kompo\Auth\Auth\LoginForm::class)->name('login.password');

	Route::middleware(['sso.validate-driver'])->group(function () {
		
		Route::get('login-sso/{service}', [Kompo\Auth\Http\Controllers\SsoController::class, 'login'])->name('login.sso');

		Route::get('login-sso/{service}/callback', [Kompo\Auth\Http\Controllers\SsoController::class, 'callback']);

	});

	Route::middleware(['signed', 'throttle:10,1'])->group(function(){

		Route::get('check-to-verify-email/{id}', Kompo\Auth\Auth\CheckToVerifyEmailForm::class)->name('check.verify.email');

		Route::get('register/{email_request_id}', Kompo\Auth\Auth\RegisterForm::class)->name('register');

		Route::get('email/verify/{hash}', [VerifyEmailController::class, '__invoke'])->name('verification.verify');

	});

	Route::get('forgot-password', Kompo\Auth\Auth\ForgotPasswordForm::class)->name('password.request');

	Route::get('reset-password/{token}', Kompo\Auth\Auth\ResetPasswordForm::class)->name('password.reset');
	
});

Route::middleware(['signed'])->group(function(){
    Route::get('report-download/{filename}', Kompo\Auth\Http\Controllers\ReportDownloadController::class)->name('report.download');
});

Route::get('surl/{short_url_code}', Kompo\Auth\Http\Controllers\ShortUrlController::class)->name('short-url');

//TEAMS
Route::middleware(['signed', 'throttle:10,1'])->group(function(){

	Route::get('accept-invitation/{id}', Kompo\Auth\Http\Controllers\TeamInvitationAcceptController::class)->name('team-invitations.accept');

	Route::layout('layouts.guest')->middleware(['guest'])->group(function(){

		Route::get('team-invitations/{invitation}', Kompo\Auth\Teams\TeamInvitationRegisterForm::class)->name('team-invitations.register');
	        
	});
});

Route::layout('layouts.dashboard')->middleware(['auth'])->group(function(){

	Route::get('teams/manage', Kompo\Auth\Teams\TeamManagementPage::class)->name('teams.manage');
	Route::get('user/manage/{id?}', Kompo\Auth\Teams\UserRolesAndPermissionsPage::class)->name('user.manage');

	Route::get('roles/manage', Kompo\Auth\Teams\Roles\RolesManager::class)->name('roles.manage');

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

Route::middleware(['auth'])->group(function(){

    Route::get('log-me-out', fn() => auth()->user()?->logMeOut());

	//Notification
	Route::post('notification-reminder/{id}/{reminder_days}', [NotificationsController::class, 'remind'])
		->name('notifications.remind');
	Route::delete('notification-delete/{id}', [NotificationsController::class, 'delete'])
		->name('notifications.delete');
    
	// Files
	Route::get('files-display/{type}/{id}', \Kompo\Auth\Http\Controllers\FilesDisplayController::class)->name('files.display');
	Route::get('files-download/{type}/{id}', \Kompo\Auth\Http\Controllers\FilesDownloadController::class)->name('files.download');
	Route::get('image-preview/{type}/{id}', \Kompo\Auth\Files\ImagePreview::class)->name('image.preview');
	Route::get('pdf-preview/{type}/{id}', \Kompo\Auth\Files\PdfPreview::class)->name('pdf.preview');
	Route::get('audio-preview/{type}/{id}', \Kompo\Auth\Files\AudioPreview::class)->name('audio.preview');
	Route::get('video-preview/{type}/{id}', \Kompo\Auth\Files\VideoPreview::class)->name('video.preview');
});