<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\RestaurantCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class RestaurantController extends Controller
{
    public function store(
        Request $request,
        RestaurantCodeService $codeService
    ) {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:2',
                'max:150',
                function ($attribute, $value, $fail) {
                    $normalized = mb_strtolower(trim($value));
                    if (Restaurant::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists()) {
                        $fail('Ya existe un restaurante registrado con este nombre.');
                    }
                },
            ],
        ], [
            'name.required' => 'El nombre del restaurante es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',
        ]);

        try {
            $restaurant = $codeService->createRestaurant(
                trim($validated['name'])
            );

            Auth::guard('restaurant')->login($restaurant);

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            return response()->json([
                'success' => true,
                'message' => 'Restaurante registrado correctamente.',
                'data' => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'code' => $restaurant->code,
                ],
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [
                    'name' => [$e->getMessage()],
                ],
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No hay códigos disponibles. Intenta más tarde o contacta al administrador.',
            ], 503);
        }
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Restaurant::orderByDesc('created_at')->get(),
        ]);
    }
}