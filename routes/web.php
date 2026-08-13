<?php

use App\Http\Controllers\DeveloperDriveOAuthController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('recordings.panel');
Route::view('/'.config('developer.panel_path'), 'admin')->name('developer.panel');
Route::get('/'.config('developer.panel_path').'/google-drive/connect', [DeveloperDriveOAuthController::class, 'redirect'])
    ->name('developer.drive.oauth.redirect');
Route::get('/'.config('developer.panel_path').'/google-drive/callback', [DeveloperDriveOAuthController::class, 'callback'])
    ->name('developer.drive.oauth.callback');
