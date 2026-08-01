<?php

use App\Http\Controllers\RedirectController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/manage', 'app');
Route::view('/admin', 'app');
Route::get('/{slug}', RedirectController::class)->where('slug', '[a-z0-9]{10}');
