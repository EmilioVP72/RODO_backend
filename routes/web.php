<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\RestaurantSessionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Middleware\AuthenticateRestaurant;

Route::middleware(AuthenticateRestaurant::class)->group(function () {

    Route::get('/restaurant/me', [
        RestaurantSessionController::class,
        'me',
    ]);

    Route::post('/orders', [
        OrderController::class,
        'store',
    ])->middleware('throttle:30,1');

    Route::get('/orders', [
        OrderController::class,
        'index',
    ]);
});
