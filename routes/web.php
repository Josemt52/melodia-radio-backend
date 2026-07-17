<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('recordings.panel');
Route::view('/'.trim((string) config('developer.panel_path'), '/'), 'welcome')->name('developer.panel');
