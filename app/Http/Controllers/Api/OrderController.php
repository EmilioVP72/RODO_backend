<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\OrderService;
use App\Services\RouteDistanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Calcula automáticamente la distancia y tarifa de entrega entre la ubicación
     * del restaurante (registrada en BD por el Admin) y el destino del pedido,
     * o aplica la tarifa fija del restaurante si tiene una asignada en BD.
     */
    public function calculateDistance(
        Request $request,
        RouteDistanceService $distanceService,
        OrderService $orderService
    ): JsonResponse {
        $restaurant = Auth::guard('restaurant')->user();

        if (
            !$restaurant instanceof Restaurant ||
            !$restaurant->is_active
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurante no autorizado o inactivo.',
            ], 403);
        }

        $validated = $request->validate([
            'street' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:150'],
            'address_reference' => ['nullable', 'string', 'max:255'],
            'map_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $hasFixedFee = $restaurant->fixed_delivery_fee !== null && (float) $restaurant->fixed_delivery_fee > 0;
        $fixedFeeAmount = $hasFixedFee ? round((float) $restaurant->fixed_delivery_fee, 2) : null;

        // Intentar calcular distancia si el restaurante tiene ubicación registrada
        $distanceResult = null;
        if (!empty($restaurant->location_url)) {
            $distanceResult = $distanceService->calculateBetweenRestaurantAndDestination($restaurant, $validated);
        }

        // Si el restaurante tiene Tarifa Fija en BD, siempre se coloca esa tarifa fija
        if ($hasFixedFee) {
            $calculatedKm = ($distanceResult && $distanceResult['success'] && $distanceResult['distance_km'] !== null)
                ? (float) $distanceResult['distance_km']
                : 0.0;

            return response()->json([
                'success' => true,
                'has_fixed_fee' => true,
                'fixed_delivery_fee' => $fixedFeeAmount,
                'delivery_fee' => $fixedFeeAmount,
                'distance_km' => $calculatedKm,
                'out_of_coverage' => false,
                'message' => sprintf('Tarifa fija establecida para %s: $%.2f MXN.', $restaurant->name, $fixedFeeAmount),
            ]);
        }

        if (!$distanceResult || !$distanceResult['success'] || $distanceResult['distance_km'] === null) {
            return response()->json([
                'success' => false,
                'has_fixed_fee' => false,
                'fixed_delivery_fee' => null,
                'delivery_fee' => null,
                'distance_km' => null,
                'out_of_coverage' => false,
                'message' => $distanceResult['message'] ?? 'No fue posible calcular la distancia automáticamente.',
            ], 200);
        }

        $km = (float) $distanceResult['distance_km'];
        $outOfCoverage = $km > 5.0;
        $fee = !$outOfCoverage ? $orderService->calculateDeliveryFee($km) : null;

        return response()->json([
            'success' => true,
            'has_fixed_fee' => false,
            'fixed_delivery_fee' => null,
            'distance_km' => $km,
            'delivery_fee' => $fee,
            'out_of_coverage' => $outOfCoverage,
            'origin_coords' => $distanceResult['origin_coords'],
            'destination_coords' => $distanceResult['destination_coords'],
            'source' => $distanceResult['source'],
            'message' => $outOfCoverage
                ? sprintf('Distancia calculada: %.1f km (supera el límite de cobertura de 5 km).', $km)
                : sprintf('Distancia calculada automáticamente: %.1f km · Tarifa: $%.2f MXN.', $km, $fee),
        ]);
    }

    public function store(
        Request $request,
        OrderService $orderService,
        RouteDistanceService $distanceService
    ): JsonResponse {
        $restaurant = Auth::guard('restaurant')->user();

        if (
            !$restaurant instanceof Restaurant ||
            !$restaurant->is_active
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurante no autorizado o inactivo.',
            ], 403);
        }

        $hasFixedFee = $restaurant->fixed_delivery_fee !== null && (float) $restaurant->fixed_delivery_fee > 0;

        // Si el restaurante tiene ubicación registrada en BD (y no tarifa fija), calcular y fijar automáticamente la distancia para evitar modificaciones manuales
        if (
            !$hasFixedFee &&
            !empty($restaurant->location_url)
        ) {
            $autoCalc = $distanceService->calculateBetweenRestaurantAndDestination($restaurant, $request->all());
            if ($autoCalc['success'] && $autoCalc['distance_km'] !== null) {
                $request->merge(['distance_km' => $autoCalc['distance_km']]);
            }
        }

        $validated = $request->validate([
            'customer_name' => [
                'nullable',
                'string',
                'max:150',
            ],

            'customer_phone' => [
                'required',
                'string',
                'max:25',
            ],

            'street' => [
                'required',
                'string',
                'max:255',
            ],

            'neighborhood' => [
                'required',
                'string',
                'max:150',
            ],

            'address_reference' => [
                'nullable',
                'string',
            ],

            'map_url' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'distance_km' => $hasFixedFee
                ? ['nullable', 'numeric', 'min:0', 'max:50']
                : ['required', 'numeric', 'between:0,5'],

            'payment_method' => [
                'nullable',
                Rule::in([
                    'Efectivo',
                    'Transferencia',
                    'Tarjeta / pedido pagado',
                    'Pago contra entrega',
                ]),
            ],

            'amount_to_collect' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'needs_change' => [
                'sometimes',
                'boolean',
            ],

            'change_from' => [
                'nullable',
                'string',
                'max:50',
                'required_if:needs_change,true',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'comments' => [
                'nullable',
                'string',
            ],

            'is_ready' => [
                'sometimes',
                'boolean',
            ],

            'care_instructions' => [
                'sometimes',
                'array',
                'max:10',
            ],

            'care_instructions.*' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        // Si tiene tarifa fija y no se envió distancia pero sí tiene ubicación, calcular distancia informativa sin bloquear
        if (
            $hasFixedFee &&
            (!isset($validated['distance_km']) || $validated['distance_km'] === null || $validated['distance_km'] === '') &&
            !empty($restaurant->location_url)
        ) {
            $autoCalc = $distanceService->calculateBetweenRestaurantAndDestination($restaurant, $validated);
            if ($autoCalc['success'] && $autoCalc['distance_km'] !== null) {
                $validated['distance_km'] = $autoCalc['distance_km'];
            }
        }

        $order = $orderService->createOrder(
            $restaurant,
            $validated
        );

        $frontendUrl = rtrim((string) env('FRONTEND_URL', 'https://rodo-frontend-phi.vercel.app'), '/');
        $responseData = $order->toArray();
        $responseData['public_token'] = $order->public_token;
        $responseData['ticket_url'] = "{$frontendUrl}/ticket/{$order->public_token}";

        return response()->json([
            'success' => true,
            'message' => 'Pedido registrado correctamente.',
            'data' => $responseData,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $restaurant = Auth::guard('restaurant')->user();

        if (
            !$restaurant instanceof Restaurant ||
            !$restaurant->is_active
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurante no autorizado o inactivo.',
            ], 403);
        }

        $orders = $restaurant->orders()
            ->with([
                'careInstructions',
                'attachments',
            ])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Pedidos obtenidos correctamente.',
            'data' => $orders,
        ]);
    }
}
