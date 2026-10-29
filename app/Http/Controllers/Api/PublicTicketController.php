<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicTicketResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicTicketController extends Controller
{
    /**
     * Devuelve la información pública del ticket para coordinar la entrega.
     * Solo lectura, sin acceso a credenciales ni datos privados del restaurante.
     */
    public function show(string $token): JsonResponse
    {
        $order = Order::with([
            'restaurant:id,name,code',
            'careInstructions',
        ])
        ->where('public_token', $token)
        ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket no encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new PublicTicketResource($order),
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
