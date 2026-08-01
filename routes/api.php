<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\LinkController;
use Illuminate\Support\Facades\Route;

Route::get('/domains', [LinkController::class, 'domains'])->middleware('throttle:60,1');
Route::post('/links', [LinkController::class, 'store'])->middleware('throttle:30,1');
Route::match(['put', 'patch'], '/links', [LinkController::class, 'update'])->middleware('throttle:60,1');
Route::get('/links/{token_id}', [LinkController::class, 'show'])->middleware('throttle:60,1');
Route::post('/links/info', [LinkController::class, 'show'])->middleware('throttle:60,1');

Route::prefix('admin')->middleware(['admin.env', 'throttle:60,1'])->group(function () {
    Route::post('/login', [AdminController::class, 'login']);
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::delete('/links/{link}', [AdminController::class, 'destroy']);
    Route::post('/domains', [AdminController::class, 'storeDomain']);
    Route::patch('/domains/{domain}', [AdminController::class, 'updateDomain']);
    Route::delete('/domains/{domain}', [AdminController::class, 'destroyDomain']);
});
