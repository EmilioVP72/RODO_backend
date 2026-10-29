<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestaurantSessionController extends Controller
{
    public function me(Request $request)
    {
        $restaurant = Auth::guard('restaurant')->user();

        if (!$restaurant || !$restaurant->is_active) {
            return response()->json([
                'message' => 'Sesión no válida.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'code' => $restaurant->code,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('restaurant')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada.',
        ]);
    }
}