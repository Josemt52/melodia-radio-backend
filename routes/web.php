<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('recordings.panel');
Route::view('/admin', 'admin')->name('developer.panel');
