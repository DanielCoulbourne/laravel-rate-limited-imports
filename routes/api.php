<?php

use App\Http\Controllers\Api\ItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware(['multi.throttle:20,10:400,60:10000,86400'])
    ->group(function () {
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/items/{item}', [ItemController::class, 'show']);
    });
