<?php

use App\Http\Controllers\BlogExecutionMediaController;
use App\Http\Controllers\ConnectorSettingsController;
use App\Http\Middleware\EnsureActiveHubUser;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/internal/blog-media/{attempt}/{digest}', BlogExecutionMediaController::class)
    ->whereNumber('attempt')
    ->where('digest', '[a-f0-9]{64}')
    ->name('blog.execution-media');

require __DIR__.'/accounts.php';

Route::post('/admin/connector-settings', ConnectorSettingsController::class)
    ->middleware(['auth', EnsureActiveHubUser::class])->name('connector.settings');
