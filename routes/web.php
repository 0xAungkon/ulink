<?php

use App\Http\Controllers\RedirectController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/manage', 'app');
$adminPath = '/'.trim((string) config('ulink.admin_path', 'admin'), '/');
Route::view($adminPath, 'app');
Route::view($adminPath.'/dashboard', 'app');
Route::view($adminPath.'/links', 'app');
Route::view($adminPath.'/link/{link}', 'app')->whereNumber('link');
Route::view($adminPath.'/domains', 'app');
Route::any('/{proxyPath}', [RedirectController::class, 'dispatch'])
    ->where('proxyPath', '.*')
    ->withoutMiddleware(ValidateCsrfToken::class);
