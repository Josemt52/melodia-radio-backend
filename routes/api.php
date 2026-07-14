<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RadioController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('api.login');

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/current', [RadioController::class, 'current'])->name('api.current');
    Route::get('/hours', [RadioController::class, 'hours'])->name('api.hours');
    Route::get('/list', [RadioController::class, 'list'])->name('api.list');
    Route::get('/play', [RadioController::class, 'play'])->name('api.play');
    Route::post('/cut', [RadioController::class, 'cut'])->name('api.cut');
    Route::post('/export', [RadioController::class, 'export'])->name('api.export');
    Route::post('/exports', [RadioController::class, 'createExportJob'])->name('api.exports.create');
    Route::get('/exports/{id}', [RadioController::class, 'exportJobStatus'])
        ->where('id', '[a-f0-9]{32}')
        ->name('api.exports.status');
    Route::get('/exports/{id}/download', [RadioController::class, 'downloadExportJob'])
        ->where('id', '[a-f0-9]{32}')
        ->name('api.exports.download');
});
