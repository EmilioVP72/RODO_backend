<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function calculateDeliveryFee(float $distanceKm): float
    {
        if ($distanceKm < 0 || $distanceKm > 5) {
            throw new \InvalidArgumentException(
                'La distancia debe estar entre 0 y 5 km.'
            );
        }

        return min(50, 40 + (2 * $distanceKm));
    }

    public function createOrder(
        Restaurant $restaurant,
        array $data
    ): Order {
        return DB::transaction(function () use ($restaurant, $data) {

            $distanceKm = (float) $data['distance_km'];

            $deliveryFee = $this->calculateDeliveryFee(
                $distanceKm
            );

            $order = Order::create([
                'restaurant_id' => $restaurant->id,

                // Folio temporal único basado en UUID.
                'folio' => 'RODO-' . strtoupper(
                    Str::random(12)
                ),

                'public_token' => (string) Str::uuid(),

                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'],
                'street' => $data['street'],
                'neighborhood' => $data['neighborhood'],
                'address_reference' => $data['address_reference'] ?? null,
                'map_url' => $data['map_url'] ?? null,

                'distance_km' => $distanceKm,
                'delivery_fee' => $deliveryFee,

                'payment_method' => $data['payment_method'] ?? null,
                'amount_to_collect' => $data['amount_to_collect'] ?? null,
                'needs_change' => $data['needs_change'] ?? false,
                'change_from' => $data['change_from'] ?? null,

                'description' => $data['description'] ?? null,
                'comments' => $data['comments'] ?? null,
                'is_ready' => $data['is_ready'] ?? false,

                'status' => 'pending',
            ]);

            foreach ($data['care_instructions'] ?? [] as $instruction) {
                $order->careInstructions()->create([
                    'instruction' => $instruction,
                ]);
            }

            return $order->load([
                'restaurant:id,name,code',
                'careInstructions',
            ]);
        });
    }
}