<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAdmin;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestaurantSessionController extends Controller
{
    /**
     * Iniciar sesión con el nombre o código de 3 dígitos del restaurante,
     * o con el código especial 000 para el Administrador.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'min:2', 'max:150'],
        ], [
            'identifier.required' => 'Ingresa el nombre o código de tu restaurante.',
        ]);

        $query = trim($validated['identifier']);

        // Acceso especial para el Administrador con el código 000
        if ($query === '000') {
            if ($request->hasSession()) {
                $request->session()->regenerate();
                $request->session()->put('is_rodo_admin', true);
            }

            $adminToken = AuthenticateAdmin::generateToken();

            return response()->json([
                'success' => true,
                'is_admin' => true,
                'message' => 'Bienvenido al Panel de Administración RODO.',
                'data' => [
                    'id' => 0,
                    'name' => 'Administrador RODO',
                    'code' => '000',
                    'role' => 'admin',
                    'is_admin' => true,
                    'token' => $adminToken,
                ],
            ]);
        }

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
            'is_admin' => false,
            'message' => 'Sesión iniciada correctamente.',
            'data' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'code' => $restaurant->code,
                'photo_url' => $restaurant->photo_url,
                'location_url' => $restaurant->location_url,
                'description' => $restaurant->description,
                'fixed_delivery_fee' => $restaurant->fixed_delivery_fee !== null ? round((float) $restaurant->fixed_delivery_fee, 2) : null,
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
                'photo_url' => $restaurant->photo_url,
                'location_url' => $restaurant->location_url,
                'description' => $restaurant->description,
                'fixed_delivery_fee' => $restaurant->fixed_delivery_fee !== null ? round((float) $restaurant->fixed_delivery_fee, 2) : null,
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
            $request->session()->forget('is_rodo_admin');
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada.',
        ]);
    }
}
