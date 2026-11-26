<?php

use App\Http\Controllers\AccountInvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/einladung/{token}', [AccountInvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:20,1')->name('accounts.accept');
Route::post('/einladung/{token}', [AccountInvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1')->name('accounts.accept.store');
