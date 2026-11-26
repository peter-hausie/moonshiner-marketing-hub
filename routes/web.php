<?php

use App\Http\Controllers\BlogExecutionMediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/internal/blog-media/{attempt}/{digest}', BlogExecutionMediaController::class)
    ->whereNumber('attempt')
    ->where('digest', '[a-f0-9]{64}')
    ->name('blog.execution-media');

require __DIR__.'/accounts.php';
