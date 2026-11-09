<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicRiderRequestResource;
use App\Models\AppSetting;
use App\Models\Restaurant;
use App\Models\RiderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class RiderRequestController extends Controller
{
    public const DAILY_LIMIT_PER_RESTAURANT = 3;

    /**
     * Calcula cuántas solicitudes rápidas ha realizado el restaurante en el día actual (America/Mexico_City).
     */
    public static function getDailyUsageForRestaurant(Restaurant $restaurant): array
    {
        $nowMx = Carbon::now('America/Mexico_City');
        $startOfDayUtc = $nowMx->copy()->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
        $endOfDayUtc = $nowMx->copy()->endOfDay()->setTimezone(config('app.timezone', 'UTC'));

        $usedToday = (int) $restaurant->riderRequests()
            ->whereBetween('created_at', [$startOfDayUtc, $endOfDayUtc])
            ->count();

        return [
            'daily_limit' => self::DAILY_LIMIT_PER_RESTAURANT,
            'used_today' => $usedToday,
            'remaining_today' => max(0, self::DAILY_LIMIT_PER_RESTAURANT - $usedToday),
        ];
    }

    /**
     * Crear una solicitud rápida de múltiples repartidores (Autenticado, límite de 3 usos por día).
     */
    public function store(Request $request): JsonResponse
    {
        AppSetting::ensureSchemaExists();

        $restaurant = Auth::guard('restaurant')->user() ?? $request->user();

        if (!$restaurant instanceof Restaurant || !$restaurant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurante no autorizado o inactivo.',
            ], 403);
        }

        // Regla anti-spam: máximo 3 usos por restaurante al día
        $usage = self::getDailyUsageForRestaurant($restaurant);
        if ($usage['used_today'] >= self::DAILY_LIMIT_PER_RESTAURANT) {
            return response()->json([
                'success' => false,
                'message' => 'Has alcanzado el límite máximo de 3 solicitudes rápidas de repartidores por día. Se restablecerá mañana a las 00:00 hrs.',
                'data' => $usage,
            ], 429);
        }

        $validated = $request->validate([
            'riders_count' => ['required', 'integer', 'min:1', 'max:4'],
            'zones' => ['required', 'array', 'min:1', 'max:4'],
            'zones.*' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'riders_count.max' => 'Solo puedes solicitar hasta 4 repartidores a la vez.',
            'zones.required' => 'Selecciona la colonia o localidad destino para cada repartidor.',
            'zones.*.required' => 'Debes seleccionar la colonia o localidad en cada lista desplegable.',
        ]);

        $ridersCount = (int) $validated['riders_count'];
        $cleanZones = array_values(array_filter(array_map('trim', $validated['zones']), fn ($z) => $z !== ''));

        if (count($cleanZones) < $ridersCount) {
            return response()->json([
                'success' => false,
                'message' => "Debes seleccionar la colonia o localidad destino para los {$ridersCount} repartidores solicitados.",
                'errors' => [
                    'zones' => ["Selecciona las {$ridersCount} zonas destino en las listas desplegables."],
                ],
            ], 422);
        }

        $finalZones = array_slice($cleanZones, 0, $ridersCount);

        // Generar folio único para la solicitud de repartidores
        do {
            $folio = 'RODO-REP-' . strtoupper(Str::random(8));
        } while (RiderRequest::where('folio', $folio)->exists());

        $publicToken = (string) Str::uuid();

        $riderRequest = $restaurant->riderRequests()->create([
            'folio' => $folio,
            'public_token' => $publicToken,
            'riders_count' => $ridersCount,
            'zones' => $finalZones,
            'note' => !empty($validated['note']) ? trim($validated['note']) : null,
            'status' => 'pending',
        ]);

        $frontendUrl = env('FRONTEND_URL', 'https://rodo-frontend-phi.vercel.app');
        $requestUrl = rtrim($frontendUrl, '/') . '/solicitud/' . $publicToken;

        $newUsage = [
            'daily_limit' => self::DAILY_LIMIT_PER_RESTAURANT,
            'used_today' => $usage['used_today'] + 1,
            'remaining_today' => max(0, self::DAILY_LIMIT_PER_RESTAURANT - ($usage['used_today'] + 1)),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de repartidores creada correctamente.',
            'data' => [
                'folio' => $riderRequest->folio,
                'riders_count' => $riderRequest->riders_count,
                'zones' => $riderRequest->zones ?? $finalZones,
                'note' => $riderRequest->note,
                'status' => $riderRequest->status,
                'public_token' => $publicToken,
                'request_url' => $requestUrl,
                'created_at' => $riderRequest->created_at?->toIso8601String(),
                'usage' => $newUsage,
                'restaurant' => [
                    'name' => $restaurant->name,
                    'code' => $restaurant->code,
                ],
            ],
        ], 201);
    }

    /**
     * Consultar una solicitud pública de repartidores por su token privado (Público, Solo Lectura).
     */
    public function showPublic(string $token)
    {
        AppSetting::ensureSchemaExists();

        $riderRequest = RiderRequest::with('restaurant')
            ->where('public_token', $token)
            ->first();

        if (! $riderRequest) {
            return response()->json([
                'message' => 'Solicitud de repartidores no encontrada.',
            ], 404);
        }

        return (new PublicRiderRequestResource($riderRequest))
            ->response()
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Marcar una solicitud pública de repartidores como completada desde el ticket o desde el panel Admin.
     */
    public function completePublic(string $token): JsonResponse
    {
        AppSetting::ensureSchemaExists();

        $riderRequest = RiderRequest::with('restaurant')
            ->where('public_token', $token)
            ->first();

        if (! $riderRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud de repartidores no encontrada.',
            ], 404);
        }

        if ($riderRequest->status !== 'completed') {
            $riderRequest->status = 'completed';
            $riderRequest->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'La solicitud de repartidores ha sido marcada como completada.',
            'data' => new PublicRiderRequestResource($riderRequest),
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * Actualizar el estatus de una solicitud de repartidores desde el CRUD de Administración.
     */
    public function updateStatusAdmin(Request $request, RiderRequest $riderRequest): JsonResponse
    {
        AppSetting::ensureSchemaExists();

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,assigned,completed,cancelled'],
        ]);

        $riderRequest->status = $validated['status'];
        $riderRequest->save();
        $riderRequest->load('restaurant');

        return response()->json([
            'success' => true,
            'message' => 'Estado de la solicitud de repartidores actualizado.',
            'data' => new PublicRiderRequestResource($riderRequest),
        ]);
    }

    /**
     * Eliminar una solicitud de repartidores desde el CRUD de Administración.
     */
    public function destroyAdmin(RiderRequest $riderRequest): JsonResponse
    {
        AppSetting::ensureSchemaExists();

        $folio = $riderRequest->folio;
        $riderRequest->delete();

        return response()->json([
            'success' => true,
            'message' => "Solicitud {$folio} eliminada correctamente.",
        ]);
    }
}
