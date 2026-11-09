<?php

namespace App\Http\Controllers\Api;

use App\Contracts\RoutingProviderInterface;
use App\Exceptions\RoutingException;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RouteController extends Controller
{
    /**
     * POST /api/v1/routes/calculate
     * Calcula la ruta real por carretera, distancia en km, duración en minutos,
     * geometría GeoJSON LineString y tarifa estimada de envío.
     */
    public function calculate(
        Request $request,
        RoutingProviderInterface $routingService,
        OrderService $orderService
    ): JsonResponse {
        $restaurant = Auth::guard('restaurant')->user();

        if (!$restaurant instanceof Restaurant || !$restaurant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurante no autorizado o inactivo.',
                'data' => null,
                'errors' => [],
            ], 403);
        }

        $validated = $request->validate([
            'origin' => ['required', 'array'],
            'origin.lat' => ['required', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination' => ['required', 'array'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            $routeData = $routingService->calculateRoute(
                [
                    'lat' => (float) $validated['origin']['lat'],
                    'lng' => (float) $validated['origin']['lng'],
                ],
                [
                    'lat' => (float) $validated['destination']['lat'],
                    'lng' => (float) $validated['destination']['lng'],
                ]
            );
        } catch (RoutingException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'data' => null,
                'errors' => [
                    'route' => [$e->getMessage()],
                ],
            ], $e->getHttpStatus());
        }

        $distanceKm = (float) $routeData['distance_km'];
        $durationMinutes = (float) $routeData['duration_minutes'];

        $hasFixedFee = $restaurant->fixed_delivery_fee !== null && (float) $restaurant->fixed_delivery_fee > 0;
        $outOfCoverage = !$hasFixedFee && $distanceKm > 5.0;

        $deliveryFee = null;
        if ($hasFixedFee) {
            $deliveryFee = round((float) $restaurant->fixed_delivery_fee, 2);
        } elseif (!$outOfCoverage) {
            $deliveryFee = $orderService->calculateDeliveryFee($distanceKm);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ruta por carretera calculada correctamente.',
            'data' => [
                'distance_km' => $distanceKm,
                'duration_minutes' => $durationMinutes,
                'geometry' => $routeData['geometry'],
                'delivery_fee' => $deliveryFee,
                'is_fixed_fee' => $hasFixedFee,
                'out_of_coverage' => $outOfCoverage,
                'provider' => $routeData['provider'] ?? 'openrouteservice',
                'origin' => [
                    'lat' => round((float) $validated['origin']['lat'], 6),
                    'lng' => round((float) $validated['origin']['lng'], 6),
                ],
                'destination' => [
                    'lat' => round((float) $validated['destination']['lat'], 6),
                    'lng' => round((float) $validated['destination']['lng'], 6),
                ],
            ],
            'errors' => (object) [],
        ], 200);
    }
}
