<?php

use App\Http\Controllers\RedirectController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/manage', 'app');
Route::view('/admin', 'app');
Route::any('/{proxyPath}', [RedirectController::class, 'dispatch'])
    ->where('proxyPath', '.*')
    ->withoutMiddleware(ValidateCsrfToken::class);
