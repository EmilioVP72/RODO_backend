<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminRestaurantController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\RestaurantSessionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PublicTicketController;
use App\Http\Controllers\Api\RiderRequestController;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\AuthenticateRestaurant;

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

    Route::post('/restaurant/login', [
        RestaurantSessionController::class,
        'login',
    ])->middleware('throttle:10,1');

    Route::middleware(AuthenticateRestaurant::class)->group(function () {

        Route::get('/restaurant/me', [
            RestaurantSessionController::class,
            'me',
        ]);

        Route::post('/restaurant/logout', [
            RestaurantSessionController::class,
            'logout',
        ]);

        Route::post('/orders/calculate-distance', [
            OrderController::class,
            'calculateDistance',
        ])->middleware('throttle:60,1');

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

    // Rutas del Panel de Administración (Código 000)
    Route::prefix('admin')->middleware(AuthenticateAdmin::class)->group(function () {

        Route::get('/summary', [
            AdminDashboardController::class,
            'summary',
        ]);

        Route::get('/restaurants', [
            AdminRestaurantController::class,
            'index',
        ]);

        Route::post('/restaurants', [
            AdminRestaurantController::class,
            'store',
        ]);

        Route::put('/restaurants/{restaurant}', [
            AdminRestaurantController::class,
            'update',
        ]);

        Route::patch('/restaurants/{restaurant}', [
            AdminRestaurantController::class,
            'update',
        ]);

        Route::delete('/restaurants/{restaurant}', [
            AdminRestaurantController::class,
            'destroy',
        ]);

    });
});
