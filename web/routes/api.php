<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BreakController;
use App\Http\Controllers\Api\ScreenshotController;
use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('api.token')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/sessions/current', [SessionController::class, 'current']);
        Route::post('/sessions/start', [SessionController::class, 'start']);
        Route::post('/sessions/{uuid}/sync', [SessionController::class, 'sync']);
        Route::post('/sessions/{uuid}/stop', [SessionController::class, 'stop']);

        Route::post('/breaks/start', [BreakController::class, 'start']);
        Route::post('/breaks/{uuid}/end', [BreakController::class, 'end']);

        Route::post('/screenshots', [ScreenshotController::class, 'store']);
    });
});
