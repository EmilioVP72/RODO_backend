<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestaurantSessionController extends Controller
{
    /**
     * Iniciar sesión con el nombre o código de 3 dígitos del restaurante.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'min:2', 'max:150'],
        ], [
            'identifier.required' => 'Ingresa el nombre o código de tu restaurante.',
        ]);

        $query = trim($validated['identifier']);
        $normalized = mb_strtolower($query);

        $restaurant = Restaurant::where('code', $query)
            ->orWhereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();

        if (!$restaurant || !$restaurant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró un restaurante activo con ese código o nombre.',
            ], 404);
        }

        Auth::guard('restaurant')->login($restaurant);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $token = $restaurant->createToken('restaurant-auth')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Sesión iniciada correctamente.',
            'data' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'code' => $restaurant->code,
                'token' => $token,
            ],
        ]);
    }

    /**
     * Obtener los datos del restaurante autenticado.
     */
    public function me(Request $request)
    {
        $restaurant = Auth::guard('restaurant')->user() ?? $request->user();

        if (!$restaurant || !$restaurant->is_active) {
            return response()->json([
                'success' => false,
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

    /**
     * Cerrar la sesión del restaurante y revocar tokens.
     */
    public function logout(Request $request)
    {
        $restaurant = Auth::guard('restaurant')->user() ?? $request->user();

        if ($restaurant && method_exists($restaurant, 'currentAccessToken')) {
            $restaurant->currentAccessToken()?->delete();
        }

        Auth::guard('restaurant')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada.',
        ]);
    }
}