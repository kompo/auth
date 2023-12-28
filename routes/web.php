<?php

use Illuminate\Support\Facades\Route;

//AUTH
Route::layout('layouts.guest')->middleware(['guest'])->group(function(){

	Route::get('login', Kompo\Auth\Auth\LoginForm::class)->name('login');

	Route::get('register', Kompo\Auth\Auth\RegisterForm::class)->name('register');

	Route::get('forgot-password', Kompo\Auth\Auth\ForgotPasswordForm::class)->name('password.request');

	Route::get('reset-password/{token}', Kompo\Auth\Auth\ResetPasswordForm::class)->name('password.reset');
	
});


//TEAMS
Route::layout('layouts.dashboard')->middleware(['auth'])->group(function(){

	Route::get('teams/manage', Kompo\Auth\Teams\TeamManagementPage::class)->name('teams.manage');

});


Route::layout('layouts.guest')->middleware(['guest:web'])->group(function(){

	Route::get('team-invitations/{invitation}', Kompo\Auth\Teams\TeamInvitationRegisterForm::class)->name('team-invitations.accept')
        ->middleware(['signed']);
        
});