<?php

use App\Http\Controllers\RedirectController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/manage', 'app');
Route::view('/admin', 'app');
Route::view('/admin/dashboard', 'app');
Route::view('/admin/links', 'app');
Route::view('/admin/link/{link}', 'app')->whereNumber('link');
Route::view('/admin/domains', 'app');
Route::any('/{proxyPath}', [RedirectController::class, 'dispatch'])
    ->where('proxyPath', '.*')
    ->withoutMiddleware(ValidateCsrfToken::class);
