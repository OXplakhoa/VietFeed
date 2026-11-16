<?php

use App\Http\Controllers\Api\TokenController;
use App\Http\Middleware\ApiBearer;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [TokenController::class, 'login']);
Route::post('auth/refresh', [TokenController::class, 'refresh']);
Route::middleware(ApiBearer::class)->group(function () {
    Route::post('auth/logout', [TokenController::class, 'logout']);
    Route::get('auth/me', [TokenController::class, 'me']);
    Route::get('stories/{story}', [TokenController::class, 'story'])->whereUuid('story');
    Route::post('boosts/toggle', [TokenController::class, 'boost']);
});
