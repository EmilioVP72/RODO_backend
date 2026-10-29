<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\RestaurantSessionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PublicTicketController;
use App\Http\Controllers\Api\RiderRequestController;

Route::prefix('v1')->group(function () {

    Route::get('/tickets/{token}', [
        PublicTicketController::class,
        'show',
    ])->middleware('throttle:60,1');

    Route::get('/rider-requests/{token}', [
        RiderRequestController::class,
        'showPublic',
    ])->middleware('throttle:60,1');

    Route::post('/restaurants', [
        RestaurantController::class,
        'store',
    ])->middleware('throttle:5,1');

    Route::middleware('auth:restaurant')->group(function () {

        Route::get('/restaurant/me', [
            RestaurantSessionController::class,
            'me',
        ]);

        Route::post('/restaurant/logout', [
            RestaurantSessionController::class,
            'logout',
        ]);

        Route::post('/orders', [
            OrderController::class,
            'store',
        ])->middleware('throttle:30,1');

        Route::get('/orders', [
            OrderController::class,
            'index',
        ]);

        Route::post('/rider-requests', [
            RiderRequestController::class,
            'store',
        ])->middleware('throttle:30,1');

    });
});