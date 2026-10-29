<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\RestaurantSessionController;

Route::prefix('v1')->group(function () {

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

    });
});