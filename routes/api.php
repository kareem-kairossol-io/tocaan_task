<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1,register');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1,login');

    Route::post('refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:10,1,refresh');

    Route::middleware('auth:api')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:api')->group(function (): void {
    Route::apiResource('orders', OrderController::class);

    // payment routes
    Route::get(
        'payment-methods',
        [PaymentController::class, 'methods']
    );

    Route::get(
        'payments',
        [PaymentController::class, 'index']
    );

    Route::get(
        'orders/{order}/payments',
        [PaymentController::class, 'history']
    );

    Route::post(
        'orders/{order}/payments',
        [PaymentController::class, 'store']
    );
});
