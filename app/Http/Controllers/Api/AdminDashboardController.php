<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RiderRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /**
     * Obtener resumen ejecutivo, KPIs y series para gráficas con filtros dinámicos.
     */
    public function summary(Request $request): JsonResponse
    {
        $period = $request->query('period', '30d');
        $restaurantId = $request->query('restaurant_id');
        $status = $request->query('status');
        $paymentMethod = $request->query('payment_method');

        $now = Carbon::now();
        $startDate = null;
        $endDate = $now->copy()->endOfDay();
        $prevStartDate = null;
        $prevEndDate = null;

        switch ($period) {
            case 'today':
                $startDate = $now->copy()->startOfDay();
                $prevStartDate = $now->copy()->subDay()->startOfDay();
                $prevEndDate = $now->copy()->subDay()->endOfDay();
                break;
            case '7d':
                $startDate = $now->copy()->subDays(6)->startOfDay();
                $prevStartDate = $now->copy()->subDays(13)->startOfDay();
                $prevEndDate = $now->copy()->subDays(7)->endOfDay();
                break;
            case '30d':
                $startDate = $now->copy()->subDays(29)->startOfDay();
                $prevStartDate = $now->copy()->subDays(59)->startOfDay();
                $prevEndDate = $now->copy()->subDays(30)->endOfDay();
                break;
            case 'month':
                $startDate = $now->copy()->startOfMonth();
                $prevStartDate = $now->copy()->subMonthNoOverflow()->startOfMonth();
                $prevEndDate = $now->copy()->subMonthNoOverflow()->endOfMonth();
                break;
            case 'custom':
                if ($request->filled('date_from')) {
                    $startDate = Carbon::parse($request->query('date_from'))->startOfDay();
                }
                if ($request->filled('date_to')) {
                    $endDate = Carbon::parse($request->query('date_to'))->endOfDay();
                }
                if ($startDate && $endDate) {
                    $daysDiff = max(1, $startDate->diffInDays($endDate) + 1);
                    $prevStartDate = $startDate->copy()->subDays($daysDiff);
                    $prevEndDate = $startDate->copy()->subSecond();
                }
                break;
            case 'all':
            default:
                $startDate = null;
                break;
        }

        // Consulta base de pedidos
        $ordersQuery = Order::with('restaurant:id,name,code,photo_url,is_active');
        $riderRequestsQuery = RiderRequest::query();

        if ($startDate && $endDate) {
            $ordersQuery->whereBetween('created_at', [$startDate, $endDate]);
            $riderRequestsQuery->whereBetween('created_at', [$startDate, $endDate]);
        }

        if ($restaurantId && $restaurantId !== 'all') {
            $ordersQuery->where('restaurant_id', $restaurantId);
            $riderRequestsQuery->where('restaurant_id', $restaurantId);
        }

        if ($status && $status !== 'all') {
            if ($status === 'completed') {
                $ordersQuery->whereIn('status', ['completed', 'delivered']);
            } else {
                $ordersQuery->where('status', $status);
            }
        }

        if ($paymentMethod && $paymentMethod !== 'all') {
            $ordersQuery->where('payment_method', $paymentMethod);
        }

        $orders = $ordersQuery->orderByDesc('created_at')->get();
        $riderRequests = $riderRequestsQuery->get();

        // Periodo anterior para comparativa porcentual
        $prevOrdersCount = 0;
        $prevDeliveryFees = 0.0;
        if ($prevStartDate && $prevEndDate) {
            $prevQuery = Order::whereBetween('created_at', [$prevStartDate, $prevEndDate]);
            if ($restaurantId && $restaurantId !== 'all') {
                $prevQuery->where('restaurant_id', $restaurantId);
            }
            if ($status && $status !== 'all') {
                $prevQuery->where('status', $status);
            }
            if ($paymentMethod && $paymentMethod !== 'all') {
                $prevQuery->where('payment_method', $paymentMethod);
            }
            $prevOrdersCount = $prevQuery->count();
            $prevDeliveryFees = (float) $prevQuery->sum('delivery_fee');
        }

        // KPIs principales
        $totalOrders = $orders->count();
        $totalDeliveryFees = (float) $orders->sum('delivery_fee');
        $avgDeliveryFee = $totalOrders > 0 ? round($totalDeliveryFees / $totalOrders, 2) : 0.0;
        $totalCollected = (float) $orders->sum('amount_to_collect');
        $avgTicketCollect = $totalOrders > 0 ? round($totalCollected / $totalOrders, 2) : 0.0;
        $totalDistanceKm = round((float) $orders->sum('distance_km'), 2);
        $avgDistanceKm = $totalOrders > 0 ? round($totalDistanceKm / $totalOrders, 2) : 0.0;
        $ordersReadyCount = $orders->where('is_ready', true)->count();
        $ordersNeedingChange = $orders->where('needs_change', true)->count();
        $ordersCompletedCount = $orders->whereIn('status', ['completed', 'delivered'])->count();
        $ordersPendingCount = $orders->whereNotIn('status', ['completed', 'delivered', 'cancelled'])->count();

        $ordersGrowthPct = $prevOrdersCount > 0
            ? round((($totalOrders - $prevOrdersCount) / $prevOrdersCount) * 100, 1)
            : ($totalOrders > 0 ? 100.0 : 0.0);

        $feesGrowthPct = $prevDeliveryFees > 0
            ? round((($totalDeliveryFees - $prevDeliveryFees) / $prevDeliveryFees) * 100, 1)
            : ($totalDeliveryFees > 0 ? 100.0 : 0.0);

        // Estadísticas de restaurantes
        $allRestaurants = Restaurant::orderBy('name')->get();
        $totalRestaurants = $allRestaurants->count();
        $activeRestaurants = $allRestaurants->where('is_active', true)->count();
        $restaurantsWithOrders = $orders->pluck('restaurant_id')->unique()->count();
        $restaurantsWithCompleteProfile = $allRestaurants->filter(function ($r) {
            return !empty($r->photo_url) && !empty($r->location_url) && !empty($r->description);
        })->count();

        // Estadísticas de solicitudes rápidas de repartidores
        $totalRiderRequests = $riderRequests->count();
        $totalRidersRequested = (int) $riderRequests->sum('riders_count');

        // 1. Gráfica de evolución diaria
        $dailyTrend = [];
        $trendStart = $startDate ? $startDate->copy() : ($orders->last()?->created_at?->copy()->startOfDay() ?? $now->copy()->subDays(13)->startOfDay());
        $trendEnd = $endDate ? $endDate->copy() : $now->copy()->endOfDay();

        // Limitar el rango visual a máximo 31 puntos para mantener estética limpia
        if ($trendStart->diffInDays($trendEnd) > 31) {
            $trendStart = $trendEnd->copy()->subDays(29)->startOfDay();
        }

        $ordersByDate = $orders->groupBy(fn ($o) => $o->created_at?->format('Y-m-d'));
        $carbonPeriod = CarbonPeriod::create($trendStart->format('Y-m-d'), $trendEnd->format('Y-m-d'));

        foreach ($carbonPeriod as $dateObj) {
            $key = $dateObj->format('Y-m-d');
            $dayOrders = $ordersByDate->get($key, collect());
            $dailyTrend[] = [
                'date' => $key,
                'label' => $dateObj->format('d/m'),
                'orders_count' => $dayOrders->count(),
                'delivery_fees' => round((float) $dayOrders->sum('delivery_fee'), 2),
                'amount_collected' => round((float) $dayOrders->sum('amount_to_collect'), 2),
                'distance_km' => round((float) $dayOrders->sum('distance_km'), 2),
            ];
        }

        // 2. Gráfica de horas pico (00:00 a 23:00)
        $ordersByHour = $orders->groupBy(fn ($o) => (int) $o->created_at?->format('G'));
        $hourlyDistribution = [];
        for ($h = 8; $h <= 23; $h++) {
            $hOrders = $ordersByHour->get($h, collect());
            $hourlyDistribution[] = [
                'hour' => $h,
                'label' => str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00',
                'orders_count' => $hOrders->count(),
                'delivery_fees' => round((float) $hOrders->sum('delivery_fee'), 2),
            ];
        }

        // 3. Top Restaurantes por volumen de pedidos y tarifas
        $topRestaurants = $orders->groupBy('restaurant_id')
            ->map(function ($group) {
                $rest = $group->first()->restaurant;
                $count = $group->count();
                return [
                    'id' => $rest?->id ?? 0,
                    'name' => $rest?->name ?? 'Restaurante',
                    'code' => $rest?->code ?? '---',
                    'photo_url' => $rest?->photo_url,
                    'is_active' => (bool) ($rest?->is_active ?? true),
                    'orders_count' => $count,
                    'delivery_fees' => round((float) $group->sum('delivery_fee'), 2),
                    'amount_collected' => round((float) $group->sum('amount_to_collect'), 2),
                    'avg_distance' => $count > 0 ? round((float) $group->avg('distance_km'), 2) : 0,
                ];
            })
            ->sortByDesc('orders_count')
            ->values()
            ->take(8)
            ->all();

        // 4. Distribución por Método de Pago
        $paymentMethods = $orders->groupBy(fn ($o) => $o->payment_method ?: 'No especificado')
            ->map(function ($group, $method) use ($totalOrders) {
                $count = $group->count();
                return [
                    'method' => $method,
                    'count' => $count,
                    'percentage' => $totalOrders > 0 ? round(($count / $totalOrders) * 100, 1) : 0,
                    'total_fees' => round((float) $group->sum('delivery_fee'), 2),
                    'total_collected' => round((float) $group->sum('amount_to_collect'), 2),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        // 5. Top Colonias / Zonas de entrega
        $topNeighborhoods = $orders->groupBy(fn ($o) => mb_convert_case(trim($o->neighborhood ?: 'Sin colonia'), MB_CASE_TITLE, 'UTF-8'))
            ->map(function ($group, $neighborhood) use ($totalOrders) {
                $count = $group->count();
                return [
                    'neighborhood' => $neighborhood,
                    'orders_count' => $count,
                    'percentage' => $totalOrders > 0 ? round(($count / $totalOrders) * 100, 1) : 0,
                    'total_fees' => round((float) $group->sum('delivery_fee'), 2),
                    'avg_distance' => $count > 0 ? round((float) $group->avg('distance_km'), 2) : 0,
                ];
            })
            ->sortByDesc('orders_count')
            ->values()
            ->take(8)
            ->all();

        // 6. Distribución por rangos de distancia
        $shortDist = $orders->filter(fn ($o) => (float) $o->distance_km <= 1.5);
        $medDist = $orders->filter(fn ($o) => (float) $o->distance_km > 1.5 && (float) $o->distance_km <= 3.0);
        $longDist = $orders->filter(fn ($o) => (float) $o->distance_km > 3.0);

        $distanceRanges = [
            [
                'range' => 'Corta (0 - 1.5 km)',
                'count' => $shortDist->count(),
                'percentage' => $totalOrders > 0 ? round(($shortDist->count() / $totalOrders) * 100, 1) : 0,
                'total_fees' => round((float) $shortDist->sum('delivery_fee'), 2),
            ],
            [
                'range' => 'Media (1.6 - 3.0 km)',
                'count' => $medDist->count(),
                'percentage' => $totalOrders > 0 ? round(($medDist->count() / $totalOrders) * 100, 1) : 0,
                'total_fees' => round((float) $medDist->sum('delivery_fee'), 2),
            ],
            [
                'range' => 'Extendida (3.1 - 5.0 km)',
                'count' => $longDist->count(),
                'percentage' => $totalOrders > 0 ? round(($longDist->count() / $totalOrders) * 100, 1) : 0,
                'total_fees' => round((float) $longDist->sum('delivery_fee'), 2),
            ],
        ];

        // 7. Últimos pedidos recientes
        $frontendUrl = rtrim((string) env('FRONTEND_URL', 'https://rodo-frontend-phi.vercel.app'), '/');
        $recentOrders = $orders->take(50)->map(function ($order) use ($frontendUrl) {
            return [
                'id' => $order->id,
                'folio' => $order->folio,
                'public_token' => $order->public_token,
                'ticket_url' => "{$frontendUrl}/ticket/{$order->public_token}",
                'customer_name' => $order->customer_name ?: 'Cliente general',
                'customer_phone' => $order->customer_phone,
                'street' => $order->street,
                'neighborhood' => $order->neighborhood,
                'distance_km' => (float) $order->distance_km,
                'delivery_fee' => (float) $order->delivery_fee,
                'payment_method' => $order->payment_method ?: 'Efectivo',
                'amount_to_collect' => $order->amount_to_collect !== null ? (float) $order->amount_to_collect : null,
                'needs_change' => (bool) $order->needs_change,
                'is_ready' => (bool) $order->is_ready,
                'status' => $order->status ?: 'pending',
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'created_at' => $order->created_at?->toIso8601String(),
                'restaurant' => [
                    'id' => $order->restaurant?->id,
                    'name' => $order->restaurant?->name ?? 'Restaurante',
                    'code' => $order->restaurant?->code ?? '---',
                    'photo_url' => $order->restaurant?->photo_url,
                ],
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'total_orders' => $totalOrders,
                    'orders_growth_pct' => $ordersGrowthPct,
                    'total_delivery_fees' => round($totalDeliveryFees, 2),
                    'fees_growth_pct' => $feesGrowthPct,
                    'avg_delivery_fee' => $avgDeliveryFee,
                    'total_collected' => round($totalCollected, 2),
                    'avg_ticket_collect' => $avgTicketCollect,
                    'total_distance_km' => $totalDistanceKm,
                    'avg_distance_km' => $avgDistanceKm,
                    'orders_ready_count' => $ordersReadyCount,
                    'orders_needing_change' => $ordersNeedingChange,
                    'orders_completed_count' => $ordersCompletedCount,
                    'orders_pending_count' => $ordersPendingCount,
                    'total_restaurants' => $totalRestaurants,
                    'active_restaurants' => $activeRestaurants,
                    'restaurants_with_orders' => $restaurantsWithOrders,
                    'restaurants_with_complete_profile' => $restaurantsWithCompleteProfile,
                    'total_rider_requests' => $totalRiderRequests,
                    'total_riders_requested' => $totalRidersRequested,
                ],
                'charts' => [
                    'daily_trend' => $dailyTrend,
                    'hourly_distribution' => $hourlyDistribution,
                    'top_restaurants' => $topRestaurants,
                    'payment_methods' => $paymentMethods,
                    'top_neighborhoods' => $topNeighborhoods,
                    'distance_ranges' => $distanceRanges,
                ],
                'recent_orders' => $recentOrders,
                'restaurants_list' => $allRestaurants->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'code' => $r->code,
                    'is_active' => (bool) $r->is_active,
                ])->values()->all(),
            ],
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}

