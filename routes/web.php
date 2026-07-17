<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('recordings.panel');
Route::view('/'.config('developer.panel_path'), 'admin')->name('developer.panel');
