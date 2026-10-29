<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicRiderRequestResource;
use App\Models\RiderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RiderRequestController extends Controller
{
    /**
     * Crear una solicitud rápida de múltiples repartidores (Autenticado).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'riders_count' => ['required', 'integer', 'min:1', 'max:10'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $restaurant = $request->user();

        // Generar folio único para la solicitud de repartidores
        do {
            $folio = 'RODO-REP-' . strtoupper(Str::random(8));
        } while (RiderRequest::where('folio', $folio)->exists());

        $publicToken = (string) Str::uuid();

        $riderRequest = $restaurant->riderRequests()->create([
            'folio' => $folio,
            'public_token' => $publicToken,
            'riders_count' => $validated['riders_count'],
            'note' => $validated['note'] ?? null,
            'status' => 'pending',
        ]);

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        $requestUrl = rtrim($frontendUrl, '/') . '/solicitud/' . $publicToken;

        return response()->json([
            'message' => 'Solicitud de repartidores creada correctamente.',
            'data' => [
                'folio' => $riderRequest->folio,
                'riders_count' => $riderRequest->riders_count,
                'note' => $riderRequest->note,
                'status' => $riderRequest->status,
                'public_token' => $publicToken,
                'request_url' => $requestUrl,
                'created_at' => $riderRequest->created_at?->toIso8601String(),
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
}
