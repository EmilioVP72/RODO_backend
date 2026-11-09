<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\RestaurantCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRestaurantController extends Controller
{
    /**
     * Listar todos los restaurantes con sus métricas y filtros para el CRUD de Admin.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', 'all');
        $profile = $request->query('profile', 'all');

        $query = Restaurant::withCount(['orders', 'riderRequests'])
            ->withSum('orders as total_delivery_fees', 'delivery_fee');

        if ($search !== '') {
            $normalized = mb_strtolower($search);
            $query->where(function ($q) use ($normalized, $search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$normalized}%"])
                  ->orWhere('code', 'LIKE', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $restaurants = $query->orderByDesc('created_at')->get();

        if ($profile === 'complete') {
            $restaurants = $restaurants->filter(function ($r) {
                return !empty($r->photo_url) && !empty($r->location_url) && !empty($r->description);
            })->values();
        } elseif ($profile === 'incomplete') {
            $restaurants = $restaurants->filter(function ($r) {
                return empty($r->photo_url) || empty($r->location_url) || empty($r->description);
            })->values();
        }

        $formatted = $restaurants->map(function (Restaurant $r) {
            return [
                'id' => $r->id,
                'name' => $r->name,
                'code' => $r->code,
                'photo_url' => $r->photo_url,
                'location_url' => $r->location_url,
                'description' => $r->description,
                'fixed_delivery_fee' => $r->fixed_delivery_fee !== null ? round((float) $r->fixed_delivery_fee, 2) : null,
                'is_active' => (bool) $r->is_active,
                'orders_count' => (int) ($r->orders_count ?? 0),
                'rider_requests_count' => (int) ($r->rider_requests_count ?? 0),
                'total_delivery_fees' => round((float) ($r->total_delivery_fees ?? 0), 2),
                'has_complete_profile' => !empty($r->photo_url) && !empty($r->location_url) && !empty($r->description),
                'created_at' => $r->created_at?->toIso8601String(),
                'updated_at' => $r->updated_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    /**
     * Crear un nuevo restaurante desde el panel de administración.
     */
    public function store(Request $request, RestaurantCodeService $codeService): JsonResponse
    {
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
            'photo_url' => ['nullable', 'string'],
            'location_url' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'fixed_delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'El nombre del restaurante es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',
        ]);

        try {
            $fixedFee = isset($validated['fixed_delivery_fee']) && $validated['fixed_delivery_fee'] !== '' && (float) $validated['fixed_delivery_fee'] > 0
                ? round((float) $validated['fixed_delivery_fee'], 2)
                : null;

            $restaurant = $codeService->createRestaurant(trim($validated['name']), [
                'photo_url' => $validated['photo_url'] ?? null,
                'location_url' => !empty($validated['location_url']) ? trim($validated['location_url']) : null,
                'description' => !empty($validated['description']) ? trim($validated['description']) : null,
                'fixed_delivery_fee' => $fixedFee,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Restaurante creado correctamente con el código #{$restaurant->code}.",
                'data' => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'code' => $restaurant->code,
                    'photo_url' => $restaurant->photo_url,
                    'location_url' => $restaurant->location_url,
                    'description' => $restaurant->description,
                    'fixed_delivery_fee' => $restaurant->fixed_delivery_fee !== null ? round((float) $restaurant->fixed_delivery_fee, 2) : null,
                    'is_active' => (bool) $restaurant->is_active,
                    'orders_count' => 0,
                    'rider_requests_count' => 0,
                    'total_delivery_fees' => 0.0,
                    'has_complete_profile' => !empty($restaurant->photo_url) && !empty($restaurant->location_url) && !empty($restaurant->description),
                    'created_at' => $restaurant->created_at?->toIso8601String(),
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['name' => [$e->getMessage()]],
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No hay códigos de 3 dígitos disponibles en este momento.',
            ], 503);
        }
    }

    /**
     * Actualizar restaurante (nombre, foto, ubicación URL, descripción, estado activo).
     */
    public function update(Request $request, Restaurant $restaurant): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:150',
                function ($attribute, $value, $fail) use ($restaurant) {
                    $normalized = mb_strtolower(trim($value));
                    $exists = Restaurant::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                        ->where('id', '!=', $restaurant->id)
                        ->exists();
                    if ($exists) {
                        $fail('Ya existe otro restaurante registrado con este nombre.');
                    }
                },
            ],
            'photo_url' => ['nullable', 'string'],
            'location_url' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'fixed_delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'El nombre del restaurante es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',
        ]);

        if (array_key_exists('name', $validated)) {
            $restaurant->name = trim($validated['name']);
        }
        if (array_key_exists('photo_url', $validated)) {
            $restaurant->photo_url = !empty($validated['photo_url']) ? $validated['photo_url'] : null;
        }
        if (array_key_exists('location_url', $validated)) {
            $restaurant->location_url = !empty($validated['location_url']) ? trim($validated['location_url']) : null;
        }
        if (array_key_exists('description', $validated)) {
            $restaurant->description = !empty($validated['description']) ? trim($validated['description']) : null;
        }
        if (array_key_exists('fixed_delivery_fee', $validated)) {
            $val = $validated['fixed_delivery_fee'];
            $restaurant->fixed_delivery_fee = ($val !== null && $val !== '' && (float) $val > 0)
                ? round((float) $val, 2)
                : null;
        }
        if (array_key_exists('is_active', $validated)) {
            $restaurant->is_active = (bool) $validated['is_active'];
        }

        $restaurant->save();
        $restaurant->loadCount(['orders', 'riderRequests']);
        $restaurant->loadSum('orders as total_delivery_fees', 'delivery_fee');

        return response()->json([
            'success' => true,
            'message' => 'Datos del restaurante actualizados correctamente.',
            'data' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'code' => $restaurant->code,
                'photo_url' => $restaurant->photo_url,
                'location_url' => $restaurant->location_url,
                'description' => $restaurant->description,
                'fixed_delivery_fee' => $restaurant->fixed_delivery_fee !== null ? round((float) $restaurant->fixed_delivery_fee, 2) : null,
                'is_active' => (bool) $restaurant->is_active,
                'orders_count' => (int) ($restaurant->orders_count ?? 0),
                'rider_requests_count' => (int) ($restaurant->rider_requests_count ?? 0),
                'total_delivery_fees' => round((float) ($restaurant->total_delivery_fees ?? 0), 2),
                'has_complete_profile' => !empty($restaurant->photo_url) && !empty($restaurant->location_url) && !empty($restaurant->description),
                'created_at' => $restaurant->created_at?->toIso8601String(),
                'updated_at' => $restaurant->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Eliminar un restaurante y sus registros asociados.
     */
    public function destroy(Restaurant $restaurant): JsonResponse
    {
        DB::transaction(function () use ($restaurant) {
            foreach ($restaurant->orders as $order) {
                $order->careInstructions()->delete();
                $order->attachments()->delete();
                $order->delete();
            }

            $restaurant->riderRequests()->delete();
            $restaurant->tokens()->delete();
            $restaurant->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Restaurante eliminado correctamente.',
        ]);
    }
}

