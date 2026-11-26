<?php

use App\Http\Controllers\DraftContextController;
use App\Http\Controllers\DraftSubmissionController;
use App\Http\Controllers\ResearchSubmissionController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/research', ResearchSubmissionController::class);

Route::post('/v1/drafts', DraftSubmissionController::class);

Route::post('/v1/draft-context', DraftContextController::class);
