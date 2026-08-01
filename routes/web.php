<?php

use App\Http\Controllers\RedirectController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/manage', 'app');
Route::view('/admin', 'app');
Route::any('/{slug}/{proxyPath?}', RedirectController::class)
    ->where('slug', '[a-z0-9]{10}')
    ->where('proxyPath', '.*')
    ->withoutMiddleware(ValidateCsrfToken::class);
