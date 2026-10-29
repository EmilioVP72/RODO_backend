<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function store(
        Request $request,
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
                'url',
                'max:2048',
            ],

            'distance_km' => [
                'required',
                'numeric',
                'between:0,5',
            ],

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
