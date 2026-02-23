<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RadioController;
use App\Http\Controllers\RecordingCutController;

Route::prefix('/api')->group(function () {
    Route::get('/list', [RadioController::class, 'list']);
    Route::post('/cut', [RadioController::class, 'cut']);
    Route::get('/play', [RadioController::class, 'play']);
    Route::get('/recordings/cut', [RecordingCutController::class, 'cut']);
});
