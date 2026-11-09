<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminRestaurantController;
use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\RestaurantSessionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PublicTicketController;
use App\Http\Controllers\Api\RiderRequestController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\AuthenticateRestaurant;

Route::prefix('v1')->group(function () {

    Route::get('/settings/branding', [
        AppSettingController::class,
        'showBranding',
    ])->middleware('throttle:120,1');

    Route::get('/tickets/{token}', [
        PublicTicketController::class,
        'show',
    ])->middleware('throttle:60,1');

    Route::post('/tickets/{token}/complete', [
        PublicTicketController::class,
        'complete',
    ])->middleware('throttle:30,1');

    Route::patch('/tickets/{token}/complete', [
        PublicTicketController::class,
        'complete',
    ])->middleware('throttle:30,1');

    Route::get('/rider-requests/{token}', [
        RiderRequestController::class,
        'showPublic',
    ])->middleware('throttle:60,1');

    Route::post('/rider-requests/{token}/complete', [
        RiderRequestController::class,
        'completePublic',
    ])->middleware('throttle:30,1');

    Route::patch('/rider-requests/{token}/complete', [
        RiderRequestController::class,
        'completePublic',
    ])->middleware('throttle:30,1');

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

        Route::post('/routes/calculate', [
            RouteController::class,
            'calculate',
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

        Route::put('/settings/branding', [
            AppSettingController::class,
            'updateBranding',
        ]);

        Route::post('/settings/branding', [
            AppSettingController::class,
            'updateBranding',
        ]);

        Route::patch('/rider-requests/{riderRequest}/status', [
            RiderRequestController::class,
            'updateStatusAdmin',
        ]);

        Route::delete('/rider-requests/{riderRequest}', [
            RiderRequestController::class,
            'destroyAdmin',
        ]);

    });
});
